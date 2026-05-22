require('dotenv').config();
// ============================================================================
//  Wixy Bot — server.js [PREMIUM FULL STACK — v3.0]
//  Multi-sessão WhatsApp + IA Groq + API REST + SSE + Métricas + Rate Limit
// ============================================================================
'use strict';

const { Client, LocalAuth } = require('whatsapp-web.js');
const qrcode = require('qrcode-terminal');
const QRCode = require('qrcode');
const axios = require('axios');
const express = require('express');
const cors = require('cors');
const mysql = require('mysql2/promise');
const fs = require('fs');
const path = require('path');
const ffmpeg = require('fluent-ffmpeg');
const rateLimit = require('express-rate-limit');

// ============================================================================
//  CONFIGURAÇÃO DO FFMPEG
// ============================================================================
if (process.platform === 'win32') {
    const ffmpegWindowsPath = 'C:\\ffmpeg\\bin\\ffmpeg.exe';
    if (fs.existsSync(ffmpegWindowsPath)) {
        ffmpeg.setFfmpegPath(ffmpegWindowsPath);
        console.log(`[FFMPEG] Usando FFmpeg do Windows: ${ffmpegWindowsPath}`);
    } else {
        console.warn(`[FFMPEG] ⚠️ FFmpeg não encontrado em ${ffmpegWindowsPath}. Áudios podem falhar.`);
        console.warn(`[FFMPEG] Baixe em: https://www.gyan.dev/ffmpeg/builds/`);
    }
} else {
    console.log('[FFMPEG] Usando FFmpeg do sistema (Linux/Mac)');
}

// ============================================================================
//  CONFIGURAÇÃO DO BANCO DE DADOS
// ============================================================================
const DB = {
    host: process.env.DB_HOST || 'srv1663.hstgr.io',
    user: process.env.DB_USER || 'u462789909_wixy',
    password: process.env.DB_PASS || '',
    database: process.env.DB_NAME || 'u462789909_wixy',
    waitForConnections: true,
    connectionLimit: 10,
    enableKeepAlive: true,
    keepAliveInitialDelay: 10000,
    connectTimeout: 30000,
    idleTimeout: 60000,
    maxIdle: 5,
};

let pool;
async function getDb() {
    if (!pool) pool = mysql.createPool(DB);
    return pool;
}

function resetarPoolSeNecessario(err) {
    if (err && (err.code === 'ECONNRESET' || err.code === 'ENOTFOUND' || err.code === 'PROTOCOL_CONNECTION_LOST')) {
        console.warn(`[DB] Erro de rede detectado (${err.code}). Resetando pool...`);
        if (pool) {
            pool.end().catch(() => {});
            pool = null;
        }
    }
}

// ============================================================================
//  CRIAÇÃO AUTOMÁTICA DE TABELAS NOVAS
// ============================================================================
async function criarTabelasSeNaoExistirem() {
    try {
        const db = await getDb();
        
        // Tabela de estatísticas de uso (rate limit por usuário)
        await db.execute(`
            CREATE TABLE IF NOT EXISTS bot_usage_stats (
                usuario_id INT PRIMARY KEY,
                msgs_ia_hoje INT DEFAULT 0,
                msgs_ar_hoje INT DEFAULT 0,
                msgs_ia_ontem INT DEFAULT 0,
                msgs_ar_ontem INT DEFAULT 0,
                msgs_ia_semana JSON,
                msgs_ar_semana JSON,
                ultimo_reset DATE DEFAULT (CURRENT_DATE),
                INDEX idx_usuario (usuario_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `);
        
        // Tabela de logs de eventos (para analytics)
        await db.execute(`
            CREATE TABLE IF NOT EXISTS bot_event_logs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT NOT NULL,
                session_id VARCHAR(100),
                tipo_evento VARCHAR(50) NOT NULL,
                dados JSON,
                criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_usuario_data (usuario_id, criado_em),
                INDEX idx_tipo (tipo_evento)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `);
        
        console.log('[DB] Tabelas de uso e logs verificadas/criadas.');
    } catch (err) {
        console.error('[DB] Erro ao criar tabelas:', err.message);
    }
}

// ============================================================================
//  SISTEMA DE MÉTRICAS (Observabilidade)
// ============================================================================
const metrics = {
    msgsProcessadas: 0,
    msgsIA: 0,
    msgsAR: 0,
    msgsFalhas: 0,
    iaLatencia: [],
    iaFalhas: 0,
    inicioServidor: Date.now(),
    sessoesConectadas: 0,
    sessoesAguardandoQR: 0,
};

function registrarLatenciaIA(ms) {
    metrics.iaLatencia.push(ms);
    if (metrics.iaLatencia.length > 1000) metrics.iaLatencia.shift();
}

function atualizarMetricasSessoes() {
    metrics.sessoesConectadas = 0;
    metrics.sessoesAguardandoQR = 0;
    sessions.forEach(s => {
        if (s.status === 'conectado') metrics.sessoesConectadas++;
        if (s.status === 'aguardando_qr' || s.status === 'aguardando_pareamento') metrics.sessoesAguardandoQR++;
    });
}

// ============================================================================
//  RATE LIMIT POR USUÁRIO
// ============================================================================
async function getLimitesPlano(planoSlug) {
    const limites = {
        free:   { ia: 50,    ar: 200   },
        mensal: { ia: 2000,  ar: 10000 },
        anual:  { ia: 10000, ar: 50000 },
    };
    return limites[planoSlug] || limites.free;
}

async function checkUsageLimit(userId, tipo = 'ia') {
    try {
        const db = await getDb();
        const planoSlug = await getPlanoSlug(userId);
        const limites = await getLimitesPlano(planoSlug);
        
        const [rows] = await db.execute(
            `SELECT msgs_ia_hoje, msgs_ar_hoje, ultimo_reset 
             FROM bot_usage_stats WHERE usuario_id = ?`, [userId]
        );
        
        const hoje = new Date().toISOString().split('T')[0];
        let stats = rows[0] || { msgs_ia_hoje: 0, msgs_ar_hoje: 0, ultimo_reset: hoje };
        
        // Reset diário
        if (stats.ultimo_reset !== hoje) {
            await db.execute(
                `INSERT INTO bot_usage_stats (usuario_id, msgs_ia_hoje, msgs_ar_hoje, ultimo_reset)
                 VALUES (?, 0, 0, CURRENT_DATE)
                 ON DUPLICATE KEY UPDATE 
                    msgs_ia_ontem = msgs_ia_hoje,
                    msgs_ar_ontem = msgs_ar_hoje,
                    msgs_ia_hoje = 0, 
                    msgs_ar_hoje = 0, 
                    ultimo_reset = CURRENT_DATE`,
                [userId]
            );
            stats = { msgs_ia_hoje: 0, msgs_ar_hoje: 0, ultimo_reset: hoje };
        }
        
        const limite = tipo === 'ia' ? limites.ia : limites.ar;
        const usado = tipo === 'ia' ? stats.msgs_ia_hoje : stats.msgs_ar_hoje;
        
        return { 
            ok: usado < limite, 
            usado, 
            limite,
            plano: planoSlug
        };
    } catch (err) {
        console.error('[USAGE] Erro ao verificar limite:', err.message);
        return { ok: true, usado: 0, limite: 999999, plano: 'free' };
    }
}

async function incrementUsage(userId, tipo = 'ia') {
    try {
        const db = await getDb();
        const col = tipo === 'ia' ? 'msgs_ia_hoje' : 'msgs_ar_hoje';
        await db.execute(
            `INSERT INTO bot_usage_stats (usuario_id, ${col}, ultimo_reset)
             VALUES (?, 1, CURRENT_DATE)
             ON DUPLICATE KEY UPDATE ${col} = ${col} + 1`, [userId]
        );
        
        // Incrementa métricas globais
        if (tipo === 'ia') metrics.msgsIA++;
        else metrics.msgsAR++;
        metrics.msgsProcessadas++;
    } catch (err) {
        console.error('[USAGE] Erro ao incrementar uso:', err.message);
    }
}

async function getUsageStats(userId) {
    try {
        const db = await getDb();
        const [rows] = await db.execute(
            `SELECT msgs_ia_hoje, msgs_ar_hoje, msgs_ia_ontem, msgs_ar_ontem, 
                    msgs_ia_semana, msgs_ar_semana, ultimo_reset
             FROM bot_usage_stats WHERE usuario_id = ?`, [userId]
        );
        
        if (!rows.length) {
            return {
                hoje: { ia: 0, ar: 0 },
                ontem: { ia: 0, ar: 0 },
                semana: { ia: [0,0,0,0,0,0,0], ar: [0,0,0,0,0,0,0] }
            };
        }
        
        const r = rows[0];
        return {
            hoje: { ia: r.msgs_ia_hoje || 0, ar: r.msgs_ar_hoje || 0 },
            ontem: { ia: r.msgs_ia_ontem || 0, ar: r.msgs_ar_ontem || 0 },
            semana: {
                ia: r.msgs_ia_semana ? JSON.parse(r.msgs_ia_semana) : [0,0,0,0,0,0,0],
                ar: r.msgs_ar_semana ? JSON.parse(r.msgs_ar_semana) : [0,0,0,0,0,0,0]
            }
        };
    } catch (err) {
        console.error('[USAGE] Erro ao buscar stats:', err.message);
        return { hoje: { ia: 0, ar: 0 }, ontem: { ia: 0, ar: 0 }, semana: { ia: [], ar: [] } };
    }
}

// ============================================================================
//  CONVERSÃO DE ÁUDIO
// ============================================================================
function converterParaOggOpus(inputBuffer) {
    return new Promise((resolve, reject) => {
        const tmpIn = path.join(require('os').tmpdir(), 'wixy_in_' + Date.now() + '.mp3');
        const tmpOut = path.join(require('os').tmpdir(), 'wixy_out_' + Date.now() + '.ogg');
        
        fs.writeFileSync(tmpIn, inputBuffer);
        
        ffmpeg(tmpIn)
            .outputOptions([
                '-c:a libopus',
                '-b:a 64k',
                '-vbr on',
                '-compression_level 10',
                '-application voip',
                '-ar 48000',
                '-ac 1'
            ])
            .output(tmpOut)
            .on('end', () => {
                try {
                    fs.unlinkSync(tmpIn);
                    const oggBuffer = fs.readFileSync(tmpOut);
                    fs.unlinkSync(tmpOut);
                    resolve(oggBuffer);
                } catch (e) {
                    reject(e);
                }
            })
            .on('error', (err) => {
                try { fs.unlinkSync(tmpIn); } catch {}
                try { fs.unlinkSync(tmpOut); } catch {}
                reject(err);
            })
            .run();
    });
}

async function enviarAudioComoMusica(client, chatId, mediaUrl) {
    const { MessageMedia } = require('whatsapp-web.js');
    const response = await axios.get(mediaUrl, { responseType: 'arraybuffer', timeout: 30000 });
    const inputBuffer = Buffer.from(response.data);
    
    let base64;
    try {
        const oggBuffer = await converterParaOggOpus(inputBuffer);
        base64 = oggBuffer.toString('base64');
        console.log('[AUDIO] Convertido para ogg/opus com sucesso.');
    } catch (err) {
        console.warn('[AUDIO] Conversão falhou, enviando original:', err.message);
        base64 = inputBuffer.toString('base64');
    }
    
    const media = new MessageMedia('audio/ogg; codecs=opus', base64, 'audio.ogg');
    await client.sendMessage(chatId, media, { sendAudioAsVoice: true });
}

// ============================================================================
//  SEGURANÇA
// ============================================================================
function sanitizarId(id) {
    if (!id) return null;
    return String(id).replace(/[^a-zA-Z0-9_-]/g, '');
}

// ============================================================================
//  MODO MANUTENÇÃO
// ============================================================================
let modoManutencao = false;

// ============================================================================
//  VERIFICAÇÃO DE TRIAL
// ============================================================================
async function trialExpirado(userId) {
    try {
        const db = await getDb();
        const [rows] = await db.execute(
            'SELECT plano, criado_em, desativado FROM usuarios WHERE id = ? LIMIT 1',
            [userId]
        );
        if (!rows.length) return false;
        const u = rows[0];
        if (u.desativado) return true;
        if (u.plano !== 'free') return false;
        
        const [cfgRows] = await db.execute(
            "SELECT valor FROM bot_ia_config WHERE chave = 'trial_dias' LIMIT 1"
        );
        const trialDias = cfgRows.length ? parseInt(cfgRows[0].valor) || 3 : 3;
        const diasDesde = Math.floor((Date.now() - new Date(u.criado_em).getTime()) / 86400000);
        return diasDesde > trialDias;
    } catch { return false; }
}

// ============================================================================
//  CONFIGURAÇÕES DO USUÁRIO
// ============================================================================
async function getUserConfig(userId) {
    try {
        const db = await getDb();
        const [rows] = await db.execute(
            'SELECT chave, valor FROM bot_ia_config_usuario WHERE usuario_id = ?',
            [userId]
        );
        const cfg = {};
        for (const row of rows) cfg[row.chave] = row.valor;
        
        const defaults = {
            ia_ativo: '1',
            ar_ativo: '1',
            temperature: '1.1',
            max_tokens: '80',
            max_chars: '300',
            delay_min: '4',
            delay_max: '14',
            system_prompt: 'Você é um atendente virtual simpático e natural. Responda de forma curta e humana. Nunca diga que é uma IA.',
            contexto_msgs: '15',
            fallback_msg: 'oi! to aqui 😊',
            fallback_ativo: '1',
            blacklist: '',
            modo_debug: '0',
            horario_inicio: '00:00',
            horario_fim: '23:59',
            fora_horario_msg: '',
            humanizar_erros: '1',
            humanizar_abrev: '1',
            humanizar_reticencias: '1',
            humanizar_emoji: '1',
            humanizar_minusc: '1',
            humanizar_pontuacao: '1',
            humanizar_girias: '1',
            humanizar_hesitacao: '1',
            humanizar_risada: '1',
            humanizar_caps: '0',
            humanizar_autocorretivo: '0',
            humanizar_repetir_palavra: '0',
            humanizar_fragmentar: '0',
            humanizar_delay_extra: '0',
            humanizar_emoji_reacao: '1',
            humanizar_ne_final: '1',
            prob_minusc: '35',
            prob_pontuacao: '50',
            prob_abrev: '25',
            prob_reticencias: '20',
            prob_erros: '15',
            prob_girias: '20',
            prob_hesitacao: '20',
            prob_risada: '60',
            prob_caps: '15',
            prob_emoji_reacao: '25',
            prob_ne_final: '20',
        };
        
        return { ...defaults, ...cfg };
    } catch (err) {
        console.error('[DB] Erro ao buscar config do usuário:', err.message);
        return {};
    }
}

async function getSessionData(sessionId) {
    try {
        const db = await getDb();
        const [rows] = await db.execute(
            'SELECT * FROM bot_whatsapp_sessoes WHERE session_id = ?',
            [sessionId]
        );
        return rows[0] || null;
    } catch { return null; }
}

async function syncAvatarUsuario(userId, fotoBase64) {
    if (!userId || !fotoBase64) return;
    try {
        const db = await getDb();
        await db.execute(
            'UPDATE usuarios SET avatar_url = ? WHERE id = ?',
            [fotoBase64, userId]
        );
    } catch (err) {
        console.error('[DB] Erro ao sincronizar avatar:', err.message);
    }
}

async function updateSessionStatus(sessionId, data) {
    try {
        const db = await getDb();
        if (data.numero === undefined && data.nome === undefined && data.foto === undefined) {
            await db.execute(
                `UPDATE bot_whatsapp_sessoes SET status = ?, atualizado_em = NOW() WHERE session_id = ?`,
                [data.status || 'desconectado', sessionId]
            );
        } else {
            await db.execute(
                `UPDATE bot_whatsapp_sessoes 
                 SET status = ?, numero = ?, nome_conta = ?, foto_url = ?, atualizado_em = NOW()
                 WHERE session_id = ?`,
                [
                    data.status || 'desconectado',
                    data.numero || null,
                    data.nome || null,
                    data.foto || null,
                    sessionId,
                ]
            );
        }
    } catch (err) {
        console.error('[DB] Erro ao atualizar sessão:', err.message);
    }
}

// ============================================================================
//  GERENCIADOR DE CHAVES DE API
// ============================================================================
const seqIndexPorPlano = new Map();

async function getApiKeys() {
    try {
        const db = await getDb();
        const [rows] = await db.execute(
            "SELECT valor FROM bot_ia_config WHERE chave = 'api_keys' LIMIT 1"
        );
        if (!rows.length) return [];
        const keys = JSON.parse(rows[0].valor || '[]');
        if (!Array.isArray(keys) || !keys.length) return [];
        keys.forEach((k, i) => { if (k.order == null) k.order = i; });
        keys.sort((a, b) => Number(a.order) - Number(b.order));
        return keys;
    } catch { return []; }
}

async function getGlobalApiConfig() {
    try {
        const db = await getDb();
        const [rows] = await db.execute(
            "SELECT chave, valor FROM bot_ia_config WHERE chave IN ('api_key_mode','api_url','modelo')"
        );
        const cfg = {};
        rows.forEach(r => cfg[r.chave] = r.valor);
        return {
            mode: (cfg.api_key_mode || 'random').trim(),
            api_url: (cfg.api_url || 'https://api.groq.com/openai/v1/chat/completions').trim(),
            modelo: (cfg.modelo || 'llama-3.3-70b-versatile').trim(),
        };
    } catch {
        return { mode: 'random', api_url: 'https://api.groq.com/openai/v1/chat/completions', modelo: 'llama-3.3-70b-versatile' };
    }
}

async function getModoPlano(planoSlug) {
    if (!planoSlug) return null;
    try {
        const db = await getDb();
        const [rows] = await db.execute(
            'SELECT api_key_mode FROM planos_site WHERE slug = ? LIMIT 1',
            [planoSlug]
        );
        const modo = rows[0]?.api_key_mode?.trim() || null;
        return (modo === 'sequential' || modo === 'random') ? modo : null;
    } catch { return null; }
}

function pickStartIndexParaPlano(planoSlug, pool, modo) {
    if (modo === 'sequential') {
        const atual = seqIndexPorPlano.get(planoSlug) ?? 0;
        const idx = atual % pool.length;
        seqIndexPorPlano.set(planoSlug, (atual + 1) % pool.length);
        return idx;
    }
    return Math.floor(Math.random() * pool.length);
}

function pickStartIndexParaModelo(planoSlug, modelId, poolSize) {
    const key = `${planoSlug}::${modelId}`;
    const atual = seqIndexPorPlano.get(key) ?? 0;
    const idx = atual % poolSize;
    seqIndexPorPlano.set(key, (atual + 1) % poolSize);
    return idx;
}

async function executarChamadaIA(entry, messages, temperature, maxTokens, modeloFallback) {
    const apiKey = (entry.key || '').trim();
    const model = (entry.model || modeloFallback || 'llama-3.3-70b-versatile').trim();
    const apiUrl = (entry.url || 'https://api.groq.com/openai/v1/chat/completions').trim();
    const label = entry.label || 'Sem label';
    
    if (!apiKey) return { skip: true, label };
    
    const start = Date.now();
    const resp = await axios.post(
        apiUrl,
        { model, messages, temperature, max_tokens: maxTokens },
        {
            headers: { 'Authorization': 'Bearer ' + apiKey, 'Content-Type': 'application/json' },
            timeout: 20000,
        }
    );
    registrarLatenciaIA(Date.now() - start);
    
    const content = resp.data?.choices?.[0]?.message?.content || null;
    return { content, label, model, apiUrl };
}

async function tentarPool(pool, startIdx, messages, temperature, maxTokens, modeloFallback, contexto) {
    const total = pool.length;
    for (let attempt = 0; attempt < total; attempt++) {
        const idx = (startIdx + attempt) % total;
        const entry = pool[idx];
        const label = entry.label || `Chave ${idx + 1}`;
        
        try {
            const result = await executarChamadaIA(entry, messages, temperature, maxTokens, modeloFallback);
            
            if (result.skip) {
                console.warn(`[IA][${contexto}] Chave "${label}" em branco, pulando.`);
                continue;
            }
            if (result.content) {
                if (attempt > 0) console.log(`[IA][${contexto}] Sucesso com "${label}" (tentativa ${attempt + 1}).`);
                return result;
            }
            console.warn(`[IA][${contexto}] Chave "${label}" retornou vazio, pulando.`);
        } catch (err) {
            const status = err.response?.status;
            const errMsg = err.response?.data?.error?.message || err.message;
            if (status === 429) console.warn(`[IA][${contexto}] "${label}" rate limit (429), próxima...`);
            else if (status === 401) console.warn(`[IA][${contexto}] "${label}" inválida/expirada (401), próxima...`);
            else console.warn(`[IA][${contexto}] "${label}" falhou (${status || 'timeout'}): ${errMsg}. Próxima...`);
            metrics.iaFalhas++;
        }
    }
    return null;
}

async function getModelosPorProvider() {
    try {
        const db = await getDb();
        const [rows] = await db.execute(
            'SELECT provider_slug, model_id FROM provider_models ORDER BY provider_slug, model_id'
        );
        const map = {};
        for (const row of rows) {
            if (!map[row.provider_slug]) map[row.provider_slug] = [];
            map[row.provider_slug].push(row.model_id);
        }
        return map;
    } catch { return {}; }
}

async function chamarIAComFallback(messages, temperature, maxTokens, modeloGlobal, modelosPermitidos = null, planoSlug = null) {
    const allKeys = await getApiKeys();
    const globalCfg = await getGlobalApiConfig();
    
    if (!allKeys.length) {
        console.error('[IA] Nenhuma chave de API cadastrada.');
        return null;
    }
    
    let poolPlano = null;
    
    if (modelosPermitidos && modelosPermitidos.length > 0) {
        const modelosPorProvider = await getModelosPorProvider();
        const modoPlanoDB = await getModoPlano(planoSlug);
        const modoPlano = modoPlanoDB ?? globalCfg.mode;
        
        const expandidas = [];
        for (const entry of allKeys) {
            const entryModel = (entry.model || '').trim();
            const entryProvider = (entry.provider || '').trim();
            
            if (entryModel === '') {
                const modelosDoProvider = modelosPorProvider[entryProvider] || [];
                const modelosVisiveis = modelosDoProvider.filter(m => modelosPermitidos.includes(m));
                if (modelosVisiveis.length > 0) {
                    for (const modelId of modelosVisiveis) {
                        expandidas.push({ ...entry, model: modelId, _expandida: true });
                    }
                } else if (modelosPermitidos.includes(modeloGlobal)) {
                    expandidas.push({ ...entry, model: modeloGlobal, _expandida: true });
                }
            } else {
                if (modelosPermitidos.includes(entryModel)) {
                    expandidas.push(entry);
                }
            }
        }
        
        poolPlano = expandidas.length > 0 ? expandidas : null;
        
        if (!poolPlano) {
            console.warn(`[IA] Plano "${planoSlug}": nenhuma chave encontrada para modelos [${modelosPermitidos.join(', ')}]. Ativando fallback global.`);
        }
    }
    
    if (poolPlano) {
        const modoPlanoDB = await getModoPlano(planoSlug);
        const modoPlano = modoPlanoDB ?? globalCfg.mode;
        
        console.log(`[IA] Plano "${planoSlug}" | modo: ${modoPlano} | pool: ${poolPlano.length} entrada(s)`);
        
        let resultado = null;
        
        if (modoPlano === 'sequential') {
            const chavesPorModelo = new Map();
            for (const modelId of modelosPermitidos) {
                chavesPorModelo.set(modelId, poolPlano.filter(e => e.model === modelId));
            }
            
            const modelosNoPool = [...chavesPorModelo.keys()].filter(m => (chavesPorModelo.get(m) || []).length > 0);
            console.log(`[IA] Plano "${planoSlug}" | prioridade de modelos: [${modelosNoPool.join(' → ')}]`);
            
            for (const modelId of modelosNoPool) {
                const chaves = chavesPorModelo.get(modelId);
                if (!chaves || chaves.length === 0) continue;
                
                const startIdx = pickStartIndexParaModelo(planoSlug, modelId, chaves.length);
                console.log(`[IA] Tentando modelo "${modelId}" | ${chaves.length} chave(s) | início: ${startIdx}`);
                
                resultado = await tentarPool(chaves, startIdx, messages, temperature, maxTokens, modeloGlobal, `plano:${planoSlug}`);
                if (resultado) break;
                
                console.warn(`[IA] Modelo "${modelId}" esgotado para plano "${planoSlug}", tentando próximo modelo...`);
            }
        } else {
            const startIdx = Math.floor(Math.random() * poolPlano.length);
            resultado = await tentarPool(poolPlano, startIdx, messages, temperature, maxTokens, modeloGlobal, `plano:${planoSlug}`);
        }
        
        if (resultado) {
            console.log(`[IA] ✅ Resposta | plano: ${planoSlug} | modelo: ${resultado.model} | chave: "${resultado.label}"`);
            return resultado;
        }
        
        console.warn(`[IA] Todas as chaves do plano "${planoSlug}" falharam. Ativando fallback global...`);
    }
    
    const allKeysExpandidas = allKeys.map(entry => {
        const entryModel = (entry.model || '').trim();
        return entryModel === '' ? { ...entry, model: modeloGlobal } : entry;
    });
    
    const modoGlobal = globalCfg.mode;
    const globalStart = modoGlobal === 'sequential'
        ? pickStartIndexParaPlano('__global__', allKeysExpandidas, 'sequential')
        : Math.floor(Math.random() * allKeysExpandidas.length);
    
    console.warn(`[IA] Fallback global | modo: ${modoGlobal} | total: ${allKeysExpandidas.length} chave(s)`);
    const resultadoGlobal = await tentarPool(allKeysExpandidas, globalStart, messages, temperature, maxTokens, globalCfg.modelo, 'global');
    
    if (!resultadoGlobal) {
        console.error('[IA] Fallback global também esgotado. Todas as chaves falharam.');
    } else {
        console.log(`[IA] ✅ Resposta (fallback global) | plano: ${planoSlug ?? 'desconhecido'} | modelo: ${resultadoGlobal.model} | chave: "${resultadoGlobal.label}"`);
    }
    return resultadoGlobal;
}

async function getApiConfig() {
    const g = await getGlobalApiConfig();
    return { api_url: g.api_url, modelo: g.modelo };
}

async function getModelosPermitidos(planoSlug) {
    if (!planoSlug) return null;
    try {
        const db = await getDb();
        const [rows] = await db.execute(
            'SELECT model_id FROM plano_modelos WHERE plano_slug = ? ORDER BY ordem ASC, id ASC',
            [planoSlug]
        );
        if (!rows.length) return null;
        return rows.map(r => r.model_id);
    } catch { return null; }
}

async function getAudioModelPlano(planoSlug) {
    if (!planoSlug) return null;
    try {
        const db = await getDb();
        const [rows] = await db.execute(
            'SELECT audio_model_id, audio_provider_slug FROM planos_site WHERE slug = ? LIMIT 1',
            [planoSlug]
        );
        if (!rows.length || !rows[0].audio_model_id) return null;
        return {
            model_id: rows[0].audio_model_id,
            provider_slug: rows[0].audio_provider_slug || 'groq',
        };
    } catch { return null; }
}

async function transcreverAudio(audioBuffer, audioMime, audioModel, providerSlug) {
    try {
        const allKeys = await getApiKeys();
        
        const keysAudio = allKeys.filter(k => {
            const kProvider = (k.provider || '').trim().toLowerCase();
            const kModel = (k.model || '').trim();
            return kProvider === providerSlug && (kModel === '' || kModel === audioModel);
        });
        
        const pool = keysAudio.length ? keysAudio
            : allKeys.filter(k => (k.provider || '').trim().toLowerCase() === providerSlug);
        
        if (!pool.length) {
            console.warn(`[AUDIO] Nenhuma chave encontrada para provider '${providerSlug}'.`);
            return null;
        }
        
        let apiUrl;
        if (providerSlug === 'groq') {
            apiUrl = 'https://api.groq.com/openai/v1/audio/transcriptions';
        } else {
            const baseUrl = (pool[0].url || '').replace(/\/chat\/completions.*$/, '/audio/transcriptions');
            apiUrl = baseUrl || 'https://api.groq.com/openai/v1/audio/transcriptions';
        }
        
        const ext = audioMime.includes('ogg') ? 'ogg'
            : audioMime.includes('mp4') ? 'mp4'
            : audioMime.includes('webm') ? 'webm'
            : audioMime.includes('mpeg') ? 'mp3'
            : 'ogg';
        
        for (const entry of pool) {
            const apiKey = (entry.key || '').trim();
            if (!apiKey) continue;
            
            try {
                const FormData = require('form-data');
                const form = new FormData();
                form.append('file', audioBuffer, { filename: `audio.${ext}`, contentType: audioMime });
                form.append('model', audioModel);
                form.append('response_format', 'json');
                form.append('language', 'pt');
                
                const resp = await axios.post(apiUrl, form, {
                    headers: {
                        ...form.getHeaders(),
                        'Authorization': 'Bearer ' + apiKey,
                    },
                    timeout: 30000,
                    maxContentLength: Infinity,
                    maxBodyLength: Infinity,
                });
                
                const texto = resp.data?.text?.trim();
                if (texto) {
                    console.log(`[AUDIO] Transcrição OK | modelo: ${audioModel} | chave: "${entry.label || 'sem label'}" | ${texto.length} chars`);
                    return texto;
                }
                console.warn(`[AUDIO] Transcrição retornou vazio para chave "${entry.label || ''}".`);
            } catch (err) {
                const status = err.response?.status;
                console.warn(`[AUDIO] Erro na transcrição (${status || 'timeout'}): ${err.message}. Tentando próxima chave...`);
            }
        }
        
        console.error('[AUDIO] Todas as chaves falharam na transcrição.');
        return null;
    } catch (err) {
        console.error('[AUDIO] Erro inesperado na transcrição:', err.message);
        return null;
    }
}

async function getPlanoSlug(userId) {
    try {
        const db = await getDb();
        const [rows] = await db.execute(
            'SELECT plano FROM usuarios WHERE id = ? LIMIT 1',
            [userId]
        );
        return rows[0]?.plano || 'free';
    } catch { return 'free'; }
}

// ============================================================================
//  GERENCIADOR DE SESSÕES
// ============================================================================
const sessions = new Map();
const sessionLocks = new Map();
const contexts = new Map();
const MAX_CONTEXT_CHATS = 5000;

function getContext(sessionId, numero) {
    if (!contexts.has(sessionId)) contexts.set(sessionId, new Map());
    const sesCtx = contexts.get(sessionId);
    if (!sesCtx.has(numero)) sesCtx.set(numero, []);
    return sesCtx.get(numero);
}

function addContext(sessionId, numero, role, content, maxMsgs) {
    const ctx = getContext(sessionId, numero);
    ctx.push({ role, content });
    const max = parseInt(maxMsgs) || 15;
    while (ctx.length > max * 2) ctx.shift();
}

function limparContextoAntigo() {
    if (contexts.size > MAX_CONTEXT_CHATS) {
        const keysToDelete = Array.from(contexts.keys()).slice(0, contexts.size - MAX_CONTEXT_CHATS);
        keysToDelete.forEach(k => contexts.delete(k));
        console.log(`[MEMORY] Contexto limpo: ${keysToDelete.length} chats removidos da RAM.`);
    }
}

// ============================================================================
//  HUMANIZAÇÃO PREMIUM
// ============================================================================
const ABREVIACOES = {
    'você': 'vc',
    'vocês': 'vcs',
    'também': 'tb',
    'por que': 'pq',
    'porque': 'pq',
    'para': 'pra',
    'está': 'tá',
    'mensagem': 'msg',
    'informações': 'infos',
    'documento': 'doc',
    'documentos': 'docs',
    'orçamento': 'orçamento',
    'telefone': 'tel',
    'número': 'nº',
    'obrigado': 'obrigado',
    'obrigada': 'obrigada',
};

const CONFIRMACOES_EMPATICAS = [
    'Perfeito.',
    'Entendi.',
    'Combinado.',
    'Certo.',
    'Sem problemas.',
    'Pode deixar.',
    'Exatamente.',
    'Tudo bem.',
    'Anotado aqui.',
    'Claro,'
];

const CONECTORES_ACAO = [
    'Deixa eu verificar aqui... ',
    'Só um momento, ',
    'Então, ',
    'Certo, ',
    'Para te ajudar melhor, ',
    'Compreendo. ',
    'Perfeito, '
];

function prob(percentual) {
    return Math.random() * 100 < parseInt(percentual);
}

function humanizar(texto, cfg) {
    let t = texto.trim();
    
    if (cfg.humanizar_risada === '1' && prob(cfg.prob_risada)) {
        t = t.replace(/\b(haha|hahaha|lol|LOL)\b/gi, () => {
            const r = ['rsrs', 'haha', '😊'];
            return r[Math.floor(Math.random() * r.length)];
        });
    }
    
    if (cfg.humanizar_minusc === '1' && prob(cfg.prob_minusc)) {
        t = t.charAt(0).toLowerCase() + t.slice(1);
    }
    
    if (cfg.humanizar_pontuacao === '1' && prob(cfg.prob_pontuacao)) {
        t = t.replace(/\.$/, '');
    }
    
    if (cfg.humanizar_reticencias === '1' && prob(cfg.prob_reticencias)) {
        t = t.replace(/,\s/g, () => prob(30) ? '... ' : ', ');
    }
    
    if (cfg.humanizar_abrev === '1' && prob(cfg.prob_abrev)) {
        for (const [palavra, abrev] of Object.entries(ABREVIACOES)) {
            t = t.replace(new RegExp('\\b' + palavra + '\\b', 'gi'), abrev);
        }
    }
    
    if (cfg.humanizar_erros === '1' && prob(cfg.prob_erros)) {
        const conector = CONECTORES_ACAO[Math.floor(Math.random() * CONECTORES_ACAO.length)];
        if (t.length > 20 && !t.startsWith(conector)) {
            t = conector + t.charAt(0).toLowerCase() + t.slice(1);
        }
    }
    
    if (cfg.humanizar_girias === '1' && prob(cfg.prob_girias)) {
        const conf = CONFIRMACOES_EMPATICAS[Math.floor(Math.random() * CONFIRMACOES_EMPATICAS.length)];
        t = conf + ' ' + t.charAt(0).toLowerCase() + t.slice(1);
    }
    
    if (cfg.humanizar_hesitacao === '1' && prob(cfg.prob_hesitacao)) {
        const hes = CONECTORES_ACAO[Math.floor(Math.random() * CONECTORES_ACAO.length)];
        if (!t.includes(hes)) t = hes + t;
    }
    
    if (cfg.humanizar_ne_final === '1' && prob(cfg.prob_ne_final)) {
        const ne = prob(50) ? ' Tudo bem?' : ' Combinado?';
        if (!t.endsWith('?')) t = t + ne;
    }
    
    if (cfg.humanizar_emoji_reacao === '1' && prob(cfg.prob_emoji_reacao)) {
        const emojis = ['👍', '🙏', '✨', '🤝', '📌'];
        t = t + ' ' + emojis[Math.floor(Math.random() * emojis.length)];
    }
    
    if (cfg.humanizar_repetir_palavra === '1' && prob(20)) {
        const enfases = ['certamente', 'com certeza', 'perfeitamente'];
        if (prob(10)) t = enfases[Math.floor(Math.random() * enfases.length)] + ', ' + t.charAt(0).toLowerCase() + t.slice(1);
    }
    
    if (cfg.humanizar_emoji === '1') {
        t = t.replace(/([\u{1F300}-\u{1FAFF}])\1+/gu, '$1');
    }
    
    const maxChars = parseInt(cfg.max_chars) || 300;
    if (t.length > maxChars) {
        t = t.slice(0, maxChars).trim();
        if (!t.endsWith('...')) t += '...';
    }
    
    return t;
}

// ============================================================================
//  VERIFICAÇÃO DE HORÁRIO
// ============================================================================
function dentroDoHorario(inicio, fim) {
    const agora = new Date();
    const [hI, mI] = (inicio || '00:00').split(':').map(Number);
    const [hF, mF] = (fim || '23:59').split(':').map(Number);
    const minAtual = agora.getHours() * 60 + agora.getMinutes();
    const minInicio = hI * 60 + mI;
    const minFim = hF * 60 + mF;
    if (minInicio <= minFim) return minAtual >= minInicio && minAtual <= minFim;
    return minAtual >= minInicio || minAtual <= minFim;
}

// ============================================================================
//  LIMPEZA SEGURA DA PASTA DE SESSÃO
// ============================================================================
async function _tentarRemoverPasta(pasta, sessionId, tentativas = 8, delayMs = 1500) {
    for (let i = 0; i < tentativas; i++) {
        if (!fs.existsSync(pasta)) return;
        try {
            fs.rmSync(pasta, { recursive: true, force: true });
            console.log(`[WA] Pasta de sessão removida: ${pasta}`);
            return;
        } catch (err) {
            if (err.code === 'EBUSY' || err.code === 'EPERM' || err.code === 'ENOTEMPTY') {
                const espera = Math.min(delayMs * Math.pow(2, i), 30000);
                console.warn(`[WA] [${sessionId}] Arquivo ocupado, nova tentativa em ${Math.round(espera/1000)}s (${i + 1}/${tentativas})...`);
                await new Promise(r => setTimeout(r, espera));
            } else {
                console.error(`[WA] Erro inesperado ao remover pasta da sessão ${sessionId}:`, err.message);
                return;
            }
        }
    }
    console.warn(`[WA] [${sessionId}] Não foi possível remover a pasta após ${tentativas} tentativas. Ignorada.`);
}

function limparPastaSessao(sessionId, aguardar = false) {
    const pastaBase = path.resolve('./sessions');
    const pasta = path.join(pastaBase, `session-${sessionId}`);
    if (!fs.existsSync(pasta)) return aguardar ? Promise.resolve() : undefined;
    
    const promessa = _tentarRemoverPasta(pasta, sessionId);
    
    if (aguardar) return promessa;
    
    promessa.catch(err => console.error(`[WA] Erro inesperado na limpeza de ${sessionId}:`, err.message));
    return undefined;
}

// ============================================================================
//  DESTRUIÇÃO COMPLETA DO CLIENTE
// ============================================================================
async function destruirCliente(client) {
    try {
        await Promise.race([
            client.destroy(),
            new Promise((_, rej) => setTimeout(() => rej(new Error('timeout')), 10000)),
        ]);
    } catch {
        try {
            const browser = client.pupBrowser;
            if (browser) await browser.close().catch(() => {});
        } catch { /* ignora */ }
    }
    await new Promise(r => setTimeout(r, 1500));
}

// ============================================================================
//  HELPERS DE PRESENÇA
// ============================================================================
function calcTypingDelay(text, cfg) {
    const chars = (text || '').length || 80;
    const cpm = 200 + (Math.random() - 0.5) * 120;
    const base = Math.round((chars / cpm) * 60 * 1000);
    const delayMin = parseInt(cfg.delay_min) * 1000 || 4000;
    const delayMax = parseInt(cfg.delay_max) * 1000 || 14000;
    return Math.min(Math.max(base, delayMin), delayMax);
}

async function enviarOnline(client) {
    try { await client.sendPresenceAvailable(); } catch { /* ignora */ }
}

function iniciarDigitando(chat, client) {
    let ativo = true;
    (async () => {
        try {
            await client.pupPage.evaluate((chatId) => {
                window.require('WAWebStreamModel').Stream.markAvailable();
                window.WWebJS.sendChatstate('typing', chatId);
            }, chat.id._serialized);
        } catch { /* ignora */ }
        
        await new Promise(r => setTimeout(r, 3000));
        
        while (ativo) {
            try {
                await client.pupPage.evaluate((chatId) => {
                    window.WWebJS.sendChatstate('typing', chatId);
                }, chat.id._serialized);
            } catch { /* ignora */ }
            await new Promise(r => setTimeout(r, 3000));
        }
        
        try {
            await client.pupPage.evaluate((chatId) => {
                window.WWebJS.sendChatstate('stop', chatId);
            }, chat.id._serialized);
        } catch { /* ignora */ }
    })();
    return () => { ativo = false; };
}

async function mostrarDigitando(chat, ms, client) {
    const stop = iniciarDigitando(chat, client);
    await new Promise(r => setTimeout(r, ms));
    stop();
    await new Promise(r => setTimeout(r, 200));
}

function encerrarOnlineApos(client, delayMs = 10000) {
    (async () => {
        try { await client.sendPresenceAvailable(); } catch { /* ignora */ }
        
        const fim = Date.now() + delayMs;
        while (Date.now() < fim) {
            const espera = Math.min(8000, fim - Date.now());
            await new Promise(r => setTimeout(r, espera));
            if (Date.now() < fim) {
                try { await client.sendPresenceAvailable(); } catch { /* ignora */ }
            }
        }
        try { await client.sendPresenceUnavailable(); } catch { /* ignora */ }
    })();
}

// ============================================================================
//  HELPERS SEGUROS
// ============================================================================
async function safeGetChat(message) {
    if (message._chatCache) return message._chatCache;
    try {
        const chat = await message.getChat();
        message._chatCache = chat;
        return chat;
    } catch (e) {
        return null;
    }
}

async function safeReply(message, client, texto) {
    try {
        await message.reply(texto);
        return;
    } catch (e1) {
        const isKnownBug = e1.message && e1.message.includes('canCheckStatusRankingPosterGating');
        if (!isKnownBug) throw e1;
        
        console.warn(`[WA] Bug canCheckStatusRankingPosterGating detectado. Ativando fallback...`);
        
        await new Promise(r => setTimeout(r, 2000));
        try {
            await client.sendMessage(message.from, texto);
            return;
        } catch (e2) {
            console.warn(`[WA] Fallback sendMessage (tentativa 2) falhou: ${e2.message}. Última tentativa...`);
        }
        
        await new Promise(r => setTimeout(r, 3000));
        await client.sendMessage(message.from, texto);
    }
}

// ============================================================================
//  INICIAR SESSÃO WHATSAPP
// ============================================================================
async function iniciarSessao(sessionId, userId) {
    if (sessions.has(sessionId)) {
        const existente = sessions.get(sessionId);
        if (existente.status !== 'erro') {
            console.log(`[WA] Sessão ${sessionId} já existe (status: ${existente.status}).`);
            return existente;
        }
        sessions.delete(sessionId);
    }
    
    if (sessionLocks.has(sessionId)) {
        console.log(`[WA] Sessão ${sessionId} já está sendo inicializada, aguardando...`);
        for (let i = 0; i < 60; i++) {
            await new Promise(r => setTimeout(r, 500));
            if (!sessionLocks.has(sessionId)) break;
        }
        return sessions.get(sessionId) || { status: 'erro', qrCode: null, qrBase64: null };
    }
    
    sessionLocks.set(sessionId, true);
    
    console.log(`\n[WA] Iniciando sessão: ${sessionId} (usuário ${userId})`);
    
    const pastaBase = path.resolve('./sessions');
    const pastaSessao = path.join(pastaBase, `session-${sessionId}`);
    const lockFile = path.join(pastaSessao, 'SingletonLock');
    
    if (fs.existsSync(lockFile)) {
        console.warn(`[WA] Lock file detectado para ${sessionId}. Limpando pasta antes de inicializar...`);
        await limparPastaSessao(sessionId, true);
    }
    
    const client = new Client({
        authStrategy: new LocalAuth({ clientId: sessionId, dataPath: './sessions' }),
        webVersionCache: {
            type: 'remote',
            remotePath: 'https://raw.githubusercontent.com/wppconnect-team/wa-version/main/html/2.3000.1015901619-alpha.html',
        },
        puppeteer: {
            headless: true,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-accelerated-2d-canvas',
                '--no-first-run',
                '--no-zygote',
                '--disable-gpu',
            ],
        },
    });
    
    const sessao = {
        client,
        userId,
        status: 'aguardando_qr',
        qrCode: null,
        qrBase64: null,
        pairingCode: null,
        pairingPhone: null,
        numero: null,
        nome: null,
        foto: null,
        qrTentativas: 0,
    };
    
    sessions.set(sessionId, sessao);
    atualizarMetricasSessoes();
    
    const MAX_QR_TENTATIVAS = 3;
    
    client.on('qr', async qr => {
        sessao.qrTentativas += 1;
        
        if (sessao.qrTentativas > MAX_QR_TENTATIVAS) {
            console.log(`[WA] Limite de ${MAX_QR_TENTATIVAS} QR codes atingido para sessão ${sessionId}. Encerrando.`);
            sessao.status = 'erro';
            sessao.qrCode = null;
            sessao.qrBase64 = null;
            await updateSessionStatus(sessionId, { status: 'desconectado' });
            sessions.delete(sessionId);
            sessionLocks.delete(sessionId);
            destruirCliente(client).finally(() => limparPastaSessao(sessionId));
            atualizarMetricasSessoes();
            return;
        }
        
        console.log(`[WA] QR gerado para sessão ${sessionId} (tentativa ${sessao.qrTentativas}/${MAX_QR_TENTATIVAS})`);
        sessao.qrCode = qr;
        sessao.status = 'aguardando_qr';
        
        try {
            sessao.qrBase64 = await QRCode.toDataURL(qr);
        } catch (e) {
            sessao.qrBase64 = null;
        }
        
        await updateSessionStatus(sessionId, { status: 'aguardando_qr' });
        atualizarMetricasSessoes();
        
        if (process.env.SHOW_QR_TERMINAL === '1') {
            qrcode.generate(qr, { small: true });
        }
    });
    
    let fotoInterval = null;
    
    client.on('ready', async () => {
        console.log(`[WA] ✅ Sessão ${sessionId} conectada!`);
        sessao.status = 'conectado';
        sessao.qrCode = null;
        sessao.qrBase64 = null;
        
        sessionLocks.delete(sessionId);
        atualizarMetricasSessoes();
        
        const _aplicarPatch = async () => {
            try {
                await client.pupPage.evaluate(() => {
                    try {
                        const mod = window.require('WAWebMsgStatusUtils');
                        if (mod && typeof mod.canCheckStatusRankingPosterGating !== 'function') {
                            mod.canCheckStatusRankingPosterGating = () => false;
                        }
                    } catch { /* módulo não disponível */ }
                });
            } catch { /* ignora */ }
        };
        _aplicarPatch();
        setTimeout(_aplicarPatch, 10000);
        setTimeout(_aplicarPatch, 30000);
        
        try {
            const numero = client.info.wid.user;
            const nome = client.info.pushname || 'Sem nome';
            let foto = null;
            
            try {
                const fotoUrl = await client.getProfilePicUrl(client.info.wid._serialized);
                if (fotoUrl) {
                    const response = await axios.get(fotoUrl, { responseType: 'arraybuffer' });
                    const base64 = Buffer.from(response.data).toString('base64');
                    const mime = response.headers['content-type'] || 'image/jpeg';
                    foto = `data:${mime};base64,${base64}`;
                }
            } catch {
                console.log('[WA] Foto de perfil não disponível.');
            }
            
            sessao.numero = numero;
            sessao.nome = nome;
            sessao.foto = foto;
            
            await updateSessionStatus(sessionId, { status: 'conectado', numero, nome, foto: foto || null });
            
            if (foto) await syncAvatarUsuario(userId, foto);
            
            console.log(`[WA] Conta: ${nome} (+${numero})`);
            
            fotoInterval = setInterval(async () => {
                try {
                    const fotoUrl = await client.getProfilePicUrl(client.info.wid._serialized);
                    if (fotoUrl) {
                        const response = await axios.get(fotoUrl, { responseType: 'arraybuffer' });
                        const base64 = Buffer.from(response.data).toString('base64');
                        const mime = response.headers['content-type'] || 'image/jpeg';
                        const novaFoto = `data:${mime};base64,${base64}`;
                        if (novaFoto !== sessao.foto) {
                            sessao.foto = novaFoto;
                            await updateSessionStatus(sessionId, {
                                status: 'conectado',
                                numero: sessao.numero,
                                nome: sessao.nome,
                                foto: novaFoto,
                            });
                            await syncAvatarUsuario(userId, novaFoto);
                            console.log(`[WA] Foto de perfil atualizada: ${sessionId}`);
                        }
                    }
                } catch { /* ignora */ }
            }, 30 * 60 * 1000);
            
        } catch (err) {
            console.error('[WA] Erro ao obter info do perfil:', err.message);
        }
    });
    
    client.on('disconnected', async reason => {
        console.log(`[WA] Sessão ${sessionId} desconectada: ${reason}`);
        sessao.status = 'desconectado';
        
        if (fotoInterval) {
            clearInterval(fotoInterval);
            fotoInterval = null;
        }
        
        await updateSessionStatus(sessionId, { status: 'desconectado' });
        
        sessions.delete(sessionId);
        sessionLocks.delete(sessionId);
        atualizarMetricasSessoes();
        
        if (reason === 'LOGOUT') {
            console.log(`[WA] LOGOUT detectado. Limpando recursos da sessão ${sessionId}...`);
            destruirCliente(client).finally(() => {
                limparPastaSessao(sessionId);
                console.log(`[WA] Sessão ${sessionId} pronta para novo QR.`);
            });
        } else {
            console.log(`[WA] Desconexão inesperada (${reason}). Tentando reconectar ${sessionId} em 5s...`);
            await destruirCliente(client);
            setTimeout(async () => {
                if (!sessions.has(sessionId) && !sessionLocks.has(sessionId)) {
                    console.log(`[WA] Reconectando sessão ${sessionId}...`);
                    await iniciarSessao(sessionId, userId);
                }
            }, 5000);
        }
    });
    
    client.on('message', async message => {
        if (message.from.includes('@g.us')) return;
        if (message.fromMe) return;
        
        // Verifica modo manutenção
        if (modoManutencao) {
            try {
                await safeReply(message, client, '🔧 Sistema em manutenção. Retornaremos em alguns minutos.');
            } catch { /* ignora */ }
            return;
        }
        
        const texto = message.body || '';
        const numero = message.from.replace('@c.us', '');
        
        try {
            const cfg = await getUserConfig(userId);
            
            const trialExp = await trialExpirado(userId);
            if (trialExp) {
                try {
                    const db = await getDb();
                    const [fbRows] = await db.execute(
                        "SELECT valor FROM bot_ia_config WHERE chave = 'fallback_plano_vencido' LIMIT 1"
                    );
                    const msgVencido = fbRows.length ? fbRows[0].valor : 'Seu período gratuito expirou. Faça upgrade para continuar usando o bot.';
                    await safeReply(message, client, msgVencido);
                } catch { /* ignora */ }
                return;
            }
            
            if (cfg.ia_ativo !== '1' && cfg.ar_ativo !== '1') return;
            
            if (!dentroDoHorario(cfg.horario_inicio, cfg.horario_fim)) {
                if (cfg.fora_horario_msg) await safeReply(message, client, cfg.fora_horario_msg);
                return;
            }
            
            if (cfg.blacklist) {
                const palavras = cfg.blacklist.split(',').map(p => p.trim().toLowerCase()).filter(Boolean);
                if (palavras.some(p => texto.toLowerCase().includes(p))) return;
            }
            
            if (cfg.modo_debug === '1') {
                const contact = await message.getContact();
                console.log(`\n[MSG] Sessão: ${sessionId}`);
                console.log(`[MSG] De: ${contact.pushname || 'Sem nome'} (+${numero})`);
                console.log(`[MSG] Texto: ${texto}`);
            }
            
            const chat = await safeGetChat(message);
            if (!chat) {
                console.warn(`[IA] Não foi possível obter o chat para sessão ${sessionId}. Mensagem ignorada.`);
                return;
            }
            
            const pausaLeitura = 600 + Math.random() * 1400;
            await new Promise(r => setTimeout(r, pausaLeitura));
            
            await enviarOnline(client);
            
            await new Promise(r => setTimeout(r, 2800 + Math.random() * 600));
            try {
                await client.pupPage.evaluate(async (chatId) => {
                    const chat = await window.WWebJS.getChat(chatId, { getAsModel: false });
                    if (chat) {
                        window.require('WAWebStreamModel').Stream.markAvailable();
                        await window.require('WAWebUpdateUnreadChatAction').sendSeen({
                            chat,
                            threadId: undefined,
                        });
                    }
                }, chat.id._serialized);
            } catch { /* ignora */ }
            
            const delayMin = parseInt(cfg.delay_min) * 1000 || 4000;
            const delayMax = parseInt(cfg.delay_max) * 1000 || 14000;
            const delayPensando = Math.floor(Math.random() * (delayMax - delayMin + 1)) + delayMin;
            await new Promise(r => setTimeout(r, delayPensando));
            
            if (cfg.humanizar_delay_extra === '1') {
                const extra = Math.floor(Math.random() * 5000) + 2000;
                await new Promise(r => setTimeout(r, extra));
            }
            
            if (cfg.ar_ativo === '1') {
                // Verifica limite de AR
                const usageAR = await checkUsageLimit(userId, 'ar');
                if (!usageAR.ok) {
                    console.log(`[AR] Limite diário atingido para usuário ${userId}`);
                    encerrarOnlineApos(client, 2000);
                    return;
                }
                
                const arProcessado = await processarAutoRespostas(userId, message, client, cfg);
                if (arProcessado) {
                    await incrementUsage(userId, 'ar');
                    encerrarOnlineApos(client, 8000);
                    return;
                }
            }
            
            const midiaResposta = await verificarGatilhoMidia(userId, texto);
            if (midiaResposta) {
                if (midiaResposta.mensagem) {
                    const typingMs = calcTypingDelay(midiaResposta.mensagem, cfg);
                    await mostrarDigitando(chat, typingMs, client);
                    await safeReply(message, client, midiaResposta.mensagem);
                }
                
                if (midiaResposta.caminho) {
                    const delayMidia = Math.floor(Math.random() * 5000) + 5000;
                    await mostrarDigitando(chat, delayMidia, client);
                    const { MessageMedia } = require('whatsapp-web.js');
                    const mediaUrl = 'https://wixy.com.br' + midiaResposta.caminho;
                    if (midiaResposta.tipo === 'audio') {
                        await enviarAudioComoMusica(client, message.from, mediaUrl);
                    } else {
                        const media = await MessageMedia.fromUrl(mediaUrl, { unsafeMime: true });
                        await client.sendMessage(message.from, media);
                    }
                }
                encerrarOnlineApos(client, 10000);
                return;
            }
            
            if (cfg.ia_ativo !== '1') { encerrarOnlineApos(client, 2000); return; }
            
            // Verifica limite de IA
            const usageIA = await checkUsageLimit(userId, 'ia');
            if (!usageIA.ok) {
                console.log(`[IA] Limite diário atingido para usuário ${userId} (${usageIA.usado}/${usageIA.limite})`);
                await safeReply(message, client, 
                    `⚠️ Limite diário de ${usageIA.limite} mensagens de IA atingido. ` +
                    `Upgrade para continuar: wixy.com.br/planos`
                );
                encerrarOnlineApos(client, 5000);
                return;
            }
            
            let textoFinal = texto;
            
            if (message.hasMedia && (message.type === 'audio' || message.type === 'ptt')) {
                const planoSlugAudio = await getPlanoSlug(userId);
                const audioModelInfo = await getAudioModelPlano(planoSlugAudio);
                
                if (audioModelInfo) {
                    console.log(`[AUDIO] Mensagem de voz recebida. Transcrevendo com "${audioModelInfo.model_id}"...`);
                    try {
                        const media = await message.downloadMedia();
                        const mimeType = media.mimetype || 'audio/ogg; codecs=opus';
                        const audioBuffer = Buffer.from(media.data, 'base64');
                        
                        const transcrito = await transcreverAudio(
                            audioBuffer,
                            mimeType,
                            audioModelInfo.model_id,
                            audioModelInfo.provider_slug
                        );
                        
                        if (transcrito) {
                            textoFinal = transcrito;
                            console.log(`[AUDIO] Transcrito (${transcrito.length} chars): "${transcrito.slice(0, 80)}${transcrito.length > 80 ? '...' : ''}"`);
                            
                            try {
                                await client.pupPage.evaluate(async (msgId) => {
                                    const Store = window.require('WAWebCollections');
                                    const msg = Store.Msg.get(msgId);
                                    if (!msg) return;
                                    if (typeof msg.sendPlayedMessage === 'function') {
                                        await msg.sendPlayedMessage();
                                    } else if (typeof msg.markPlayed === 'function') {
                                        await msg.markPlayed();
                                    } else {
                                        const SendPlayedMsgAction = window.require('WAWebSendPlayedMsgAction');
                                        if (SendPlayedMsgAction) await SendPlayedMsgAction.sendPlayedMsg(msg);
                                    }
                                }, message.id._serialized);
                                console.log('[AUDIO] Marcado como reproduzido (bolinha azul).');
                            } catch (playErr) {
                                console.warn('[AUDIO] Não foi possível marcar como reproduzido:', playErr.message);
                            }
                        } else {
                            console.warn('[AUDIO] Transcrição falhou. Respondendo com fallback.');
                            if (cfg.fallback_ativo === '1') {
                                const fbMs = calcTypingDelay(cfg.fallback_msg || '', cfg);
                                await mostrarDigitando(chat, Math.min(fbMs, 3000), client);
                                await safeReply(message, client, cfg.fallback_msg || 'oi! to aqui 😊');
                            }
                            encerrarOnlineApos(client, 10000);
                            return;
                        }
                    } catch (audioErr) {
                        console.error('[AUDIO] Erro ao baixar/transcrever áudio:', audioErr.message);
                        encerrarOnlineApos(client, 10000);
                        return;
                    }
                } else {
                    console.log('[AUDIO] Voz recebida, mas nenhum modelo de áudio configurado no plano. Ignorando.');
                    encerrarOnlineApos(client, 5000);
                    return;
                }
            } else if (message.hasMedia && !texto) {
                return;
            }
            
            const ctxMsgs = getContext(sessionId, numero);
            addContext(sessionId, numero, 'user', textoFinal, cfg.contexto_msgs);
            
            const apiConfig = await getApiConfig();
            
            const planoSlug = await getPlanoSlug(userId);
            const modelosPermitidos = await getModelosPermitidos(planoSlug);
            if (modelosPermitidos) {
                console.log(`[PLANO] Usuário ${userId} (${planoSlug}): modelos permitidos → [${modelosPermitidos.join(', ')}]`);
            }
            
            await enviarOnline(client);
            await new Promise(r => setTimeout(r, 300));
            
            const pararDigitando = iniciarDigitando(chat, client);
            let iaResult;
            try {
                iaResult = await chamarIAComFallback(
                    [
                        { role: 'system', content: cfg.system_prompt },
                        ...ctxMsgs,
                    ],
                    parseFloat(cfg.temperature) || 1.1,
                    parseInt(cfg.max_tokens) || 80,
                    apiConfig.modelo,
                    modelosPermitidos,
                    planoSlug
                );
            } finally {
                pararDigitando();
                await new Promise(r => setTimeout(r, 100));
            }
            
            if (!iaResult) {
                console.error('[IA] Todas as chaves falharam.');
                metrics.msgsFalhas++;
                if (cfg.fallback_ativo === '1') {
                    const fbMs = calcTypingDelay(cfg.fallback_msg || '', cfg);
                    await mostrarDigitando(chat, Math.min(fbMs, 3000), client);
                    await safeReply(message, client, cfg.fallback_msg || 'oi! to aqui 😊');
                }
                return;
            }
            
            let iaTexto = iaResult.content;
            
            const promptMidias = await verificarGatilhoPrompt(userId, iaTexto);
            let iaTextoLimpo = iaTexto.replace(/\[ENVIAR:[^\]]+\]/gi, '').trim();
            
            addContext(sessionId, numero, 'assistant', iaTextoLimpo, cfg.contexto_msgs);
            iaTextoLimpo = humanizar(iaTextoLimpo, cfg);
            
            if (cfg.modo_debug === '1') {
                console.log(`[IA] Resposta: ${iaTextoLimpo}`);
                if (promptMidias) console.log(`[IA] Gatilhos prompt detectados:`, promptMidias.map(m => m.marcador));
            }
            
            const typingFinal = calcTypingDelay(iaTextoLimpo, cfg);
            
            if (cfg.humanizar_fragmentar === '1') {
                const meio = Math.floor(iaTextoLimpo.length / 2);
                const espaco = iaTextoLimpo.indexOf(' ', meio);
                if (espaco > 0 && espaco < iaTextoLimpo.length - 5) {
                    const p1 = iaTextoLimpo.slice(0, espaco);
                    const p2 = iaTextoLimpo.slice(espaco + 1);
                    await mostrarDigitando(chat, calcTypingDelay(p1, cfg), client);
                    await safeReply(message, client, p1);
                    const pausaFragmento = 1200 + Math.random() * 2500;
                    await new Promise(r => setTimeout(r, pausaFragmento));
                    await mostrarDigitando(chat, calcTypingDelay(p2, cfg), client);
                    await client.sendMessage(message.from, p2);
                } else {
                    await mostrarDigitando(chat, typingFinal, client);
                    await safeReply(message, client, iaTextoLimpo);
                }
            } else {
                await mostrarDigitando(chat, typingFinal, client);
                await safeReply(message, client, iaTextoLimpo);
            }
            
            // Incrementa uso de IA
            await incrementUsage(userId, 'ia');
            
            if (promptMidias && promptMidias.length) {
                const { MessageMedia } = require('whatsapp-web.js');
                for (const pm of promptMidias) {
                    const delayMidia = Math.floor(Math.random() * 3000) + 3000;
                    await mostrarDigitando(chat, delayMidia, client);
                    try {
                        const mediaUrl = 'https://wixy.com.br' + pm.caminho;
                        if (pm.tipo === 'audio') {
                            await enviarAudioComoMusica(client, message.from, mediaUrl);
                        } else {
                            const media = await MessageMedia.fromUrl(mediaUrl, { unsafeMime: true });
                            await client.sendMessage(message.from, media);
                        }
                        if (cfg.modo_debug === '1') {
                            console.log(`[PROMPT-MEDIA] Enviado: ${pm.marcador} → ${pm.caminho}`);
                        }
                    } catch (errMedia) {
                        console.error(`[PROMPT-MEDIA] Erro ao enviar ${pm.marcador}:`, errMedia.message);
                    }
                }
            }
            
            encerrarOnlineApos(client, 10000);
            
        } catch (erro) {
            console.error(`[IA] Erro na sessão ${sessionId}:`, erro.message);
            metrics.msgsFalhas++;
            try { await client.sendPresenceUnavailable(); } catch { /* ignora */ }
            try {
                const cfg = await getUserConfig(userId);
                if (cfg.fallback_ativo === '1') {
                    await client.sendMessage(message.from, cfg.fallback_msg || 'oi! to aqui 😊');
                }
            } catch { /* ignora */ }
        }
    });
    
    client.initialize().catch(async err => {
        console.error(`[WA] Erro ao inicializar sessão ${sessionId}:`, err.message);
        sessao.status = 'erro';
        sessions.delete(sessionId);
        sessionLocks.delete(sessionId);
        await updateSessionStatus(sessionId, { status: 'desconectado' });
        atualizarMetricasSessoes();
        
        if (err.message && err.message.includes('already running')) {
            console.warn(`[WA] Browser preso detectado para ${sessionId}. Iniciando limpeza em background...`);
            destruirCliente(client).finally(() => {
                limparPastaSessao(sessionId);
                console.log(`[WA] [${sessionId}] Limpeza iniciada. A próxima requisição ao /session/start irá gerar novo QR.`);
            });
        }
    });
    
    return sessao;
}

// ============================================================================
//  VERIFICAR GATILHO DE MÍDIA
// ============================================================================
async function verificarGatilhoMidia(userId, texto) {
    try {
        const db = await getDb();
        const [rows] = await db.execute(
            "SELECT * FROM bot_ia_midias_usuario WHERE usuario_id = ? AND (tipo_gatilho = 'direto' OR tipo_gatilho IS NULL)",
            [userId]
        );
        const textoLower = texto.toLowerCase();
        for (const midia of rows) {
            const gatilhos = midia.gatilho.split(',').map(g => g.trim().toLowerCase());
            if (gatilhos.some(g => textoLower.includes(g))) {
                return {
                    mensagem: midia.descricao || null,
                    caminho: midia.caminho,
                    tipo: midia.tipo,
                };
            }
        }
        return null;
    } catch { return null; }
}

async function verificarGatilhoPrompt(userId, iaTexto) {
    const regex = /\[ENVIAR:([^\]]+)\]/gi;
    const matches = [...iaTexto.matchAll(regex)];
    if (!matches.length) return null;
    try {
        const db = await getDb();
        const [rows] = await db.execute(
            "SELECT * FROM bot_ia_midias_usuario WHERE usuario_id = ? AND tipo_gatilho = 'prompt'",
            [userId]
        );
        
        const resultados = [];
        for (const match of matches) {
            const chave = match[1].trim().toLowerCase();
            for (const midia of rows) {
                const gatilhos = midia.gatilho.split(',').map(g => g.trim().toLowerCase());
                if (gatilhos.includes(chave)) {
                    resultados.push({
                        marcador: match[0],
                        mensagem: midia.descricao || null,
                        caminho: midia.caminho,
                        tipo: midia.tipo,
                    });
                    break;
                }
            }
        }
        return resultados.length ? resultados : null;
    } catch { return null; }
}

// ============================================================================
//  AUTO RESPOSTAS
// ============================================================================
const messageQueues = new Map();

async function processarFilaMensagens(chatId, fn) {
    const queue = messageQueues.get(chatId) || Promise.resolve();
    const next = queue.then(fn).catch(err => console.error('[AR Queue]', err.message));
    messageQueues.set(chatId, next);
    return next;
}

async function buscarAutoRespostas(userId) {
    try {
        const db = await getDb();
        const [rows] = await db.execute(
            'SELECT * FROM bot_auto_respostas WHERE usuario_id = ? AND ativo = 1 ORDER BY ordem ASC, criado_em DESC',
            [userId]
        );
        return rows;
    } catch { return []; }
}

function verificarGatilhoAR(texto, gatilhosJson) {
    try {
        const gatilhos = JSON.parse(gatilhosJson);
        const textoLower = texto.toLowerCase();
        return gatilhos.some(g => textoLower.includes(g.toLowerCase().trim()));
    } catch { return false; }
}

async function delayHumanizadoAR(chat, client, segundosMin, segundosMax, texto = '') {
    const ms = (Math.random() * (segundosMax - segundosMin) + segundosMin) * 1000;
    try {
        await chat.sendStateTyping();
        await new Promise(r => setTimeout(r, Math.min(ms, 8000)));
        if (ms > 8000) {
            await chat.sendStateTyping();
            await new Promise(r => setTimeout(r, ms - 8000));
        }
        await chat.clearState();
    } catch {
        await new Promise(r => setTimeout(r, ms));
    }
}

async function personalizarMensagem(texto, message) {
    try {
        const contact = await message.getContact();
        const nome = contact.pushname || contact.name || '';
        const primeiro = nome.split(' ')[0] || '';
        return texto
            .replace(/{nome}/gi, nome)
            .replace(/{primeiro_nome}/gi, primeiro)
            .replace(/{numero}/gi, message.from.replace('@c.us', ''));
    } catch { return texto; }
}

async function enviarMensagemAR(client, message, tipoMensagem, payload, cfg) {
    const chat = await message.getChat().catch(() => null);
    const chatId = message.from;
    try {
        switch (tipoMensagem) {
            case 'texto': {
                const texto = await personalizarMensagem(payload.texto || '', message);
                if (!texto) break;
                const typingMs = calcTypingDelay(texto, cfg);
                if (chat) await mostrarDigitando(chat, typingMs, client);
                await safeReply(message, client, texto);
                break;
            }
            
            case 'reply_buttons': {
                const body = await personalizarMensagem(payload.body || '', message);
                const buttons = (payload.buttons || [])
                    .filter(b => b.text)
                    .slice(0, 3)
                    .map(b => b.text);
                if (chat) { await chat.sendStateTyping(); await new Promise(r => setTimeout(r, 1200)); await chat.clearState(); }
                if (!buttons.length) {
                    await safeReply(message, client, body);
                    break;
                }
                const txtFallback = body + '\n\n' + buttons.map((b, i) => `${i+1}. ${b}`).join('\n');
                await client.sendMessage(chatId, txtFallback);
                break;
            }
            
            case 'list_message': {
                const sections = (payload.sections || []).map(sec => ({
                    title: sec.title || '',
                    rows: (sec.rows || []).map(r => ({
                        title: r.title || '',
                        description: r.description || '',
                        rowId: r.rowId || r.title.toLowerCase().replace(/\s+/g,'_'),
                    })),
                }));
                if (chat) { await chat.sendStateTyping(); await new Promise(r => setTimeout(r, 1000)); await chat.clearState(); }
                if (!sections.length || !sections[0].rows?.length) {
                    await safeReply(message, client, payload.title || payload.description || '');
                    break;
                }
                let txt = (payload.title ? `*${payload.title}*\n` : '') + (payload.description || '') + '\n\n';
                sections.forEach(sec => {
                    if (sec.title) txt += `*${sec.title}*\n`;
                    sec.rows.forEach(r => { txt += `▸ ${r.title}${r.description ? ' — ' + r.description : ''}\n`; });
                });
                await client.sendMessage(chatId, txt.trim());
                break;
            }
            
            case 'cta_button': {
                const body = await personalizarMensagem(payload.body || '', message);
                if (chat) { await chat.sendStateTyping(); await new Promise(r => setTimeout(r, 1000)); await chat.clearState(); }
                let ctaTxt = body + '\n\n';
                if (payload.type === 'url' && payload.url) {
                    ctaTxt += `🔗 *${payload.btnText || 'Acessar'}*: ${payload.url}`;
                } else if (payload.type === 'phone' && payload.phone) {
                    ctaTxt += `📞 *${payload.btnText || 'Ligar'}*: ${payload.phone}`;
                }
                await client.sendMessage(chatId, ctaTxt.trim());
                break;
            }
            
            case 'poll': {
                const { Poll } = require('whatsapp-web.js');
                const question = payload.question || 'Enquete';
                const options = (payload.options || []).filter(Boolean).slice(0, 12);
                if (!options.length) break;
                if (chat) { await chat.sendStateTyping(); await new Promise(r => setTimeout(r, 800)); await chat.clearState(); }
                try {
                    const poll = new Poll(question, options, { allowMultipleAnswers: payload.allowMultiple || false });
                    await client.sendMessage(chatId, poll);
                } catch {
                    const txt = `📊 *${question}*\n\n` + options.map((o, i) => `${i+1}. ${o}`).join('\n');
                    await client.sendMessage(chatId, txt);
                }
                break;
            }
            
            case 'product': {
                if (payload.body) {
                    const text = await personalizarMensagem(payload.body, message);
                    if (chat) { await chat.sendStateTyping(); await new Promise(r => setTimeout(r, 900)); await chat.clearState(); }
                    await client.sendMessage(chatId, text);
                }
                if (payload.catalogId && payload.retailerId) {
                    try {
                        await client.pupPage.evaluate(async (cid, pid, rid, toId) => {
                            const catalog = { id: cid };
                            const product = { id: pid, retailer_id: rid, catalog_id: cid };
                            const WA = window.Store || window.require('WAWebCollections');
                            if (WA && WA.sendProductMessage) {
                                await WA.sendProductMessage({ product, catalog, toId });
                            }
                        }, payload.catalogId, payload.catalogId, payload.retailerId, chatId);
                    } catch(e) {
                        console.warn('[AR Product] Fallback texto:', e.message);
                        await client.sendMessage(chatId, `🛒 Produto disponível em nosso catálogo. ID: ${payload.retailerId}`);
                    }
                }
                break;
            }
            
            case 'flow': {
                const body = await personalizarMensagem(payload.body || '', message);
                const header = await personalizarMensagem(payload.header || '', message);
                if (chat) { await chat.sendStateTyping(); await new Promise(r => setTimeout(r, 1000)); await chat.clearState(); }
                let flowTxt = '';
                if (header) flowTxt += `*${header}*\n`;
                if (body) flowTxt += `${body}\n`;
                if (payload.ctaText) flowTxt += `\n📝 *${payload.ctaText}*`;
                if (payload.flowId) flowTxt += `\n\n_Flow ID: ${payload.flowId}_`;
                await client.sendMessage(chatId, flowTxt.trim() || '📝 Preencha nosso formulário.');
                break;
            }
            
            case 'location_request': {
                const body = await personalizarMensagem(payload.body || 'Para continuar, preciso da sua localização 📍', message);
                if (chat) { await chat.sendStateTyping(); await new Promise(r => setTimeout(r, 900)); await chat.clearState(); }
                try {
                    await client.sendMessage(chatId, body, { requestLocation: true });
                } catch {
                    await client.sendMessage(chatId, body + '\n\n📍 Por favor, clique em 📎 > Localização para compartilhar sua posição.');
                }
                break;
            }
            
            case 'sequencial': {
                const msgs = payload.messages || [];
                for (let i = 0; i < msgs.length; i++) {
                    const item = msgs[i];
                    if (!item.texto) continue;
                    const delayMs = ((item.delay || 2) * 1000);
                    if (chat) {
                        await chat.sendStateTyping();
                        await new Promise(r => setTimeout(r, Math.min(delayMs, 5000)));
                        await chat.clearState();
                        if (delayMs > 5000) await new Promise(r => setTimeout(r, delayMs - 5000));
                    } else {
                        await new Promise(r => setTimeout(r, delayMs));
                    }
                    const texto = await personalizarMensagem(item.texto, message);
                    await client.sendMessage(chatId, texto);
                    if (i < msgs.length - 1) await new Promise(r => setTimeout(r, 400 + Math.random() * 600));
                }
                break;
            }
            
            default:
                console.warn(`[AR] Tipo desconhecido: ${tipoMensagem}`);
        }
    } catch (err) {
        console.error(`[AR] Erro ao enviar ${tipoMensagem}:`, err.message);
        try {
            const fallbackTxt = payload.texto || payload.body || payload.question || 'Olá! Em que posso ajudar?';
            if (fallbackTxt) await client.sendMessage(chatId, fallbackTxt);
        } catch {}
    }
}

async function processarAutoRespostas(userId, message, client, cfg) {
    const texto = (message.body || '').trim();
    if (!texto) return false;
    const autoRespostas = await buscarAutoRespostas(userId);
    if (!autoRespostas.length) return false;
    
    for (const ar of autoRespostas) {
        if (!verificarGatilhoAR(texto, ar.gatilhos)) continue;
        
        const sessionId = message.from;
        console.log(`[AR] Gatilho "${ar.nome}" ativado para ${sessionId}`);
        
        let payload;
        try {
            payload = JSON.parse(ar.payload);
        } catch {
            payload = { texto: ar.payload };
        }
        
        await processarFilaMensagens(message.from, async () => {
            const chat = await message.getChat().catch(() => null);
            await delayHumanizadoAR(chat, client, ar.delay_min || 2, ar.delay_max || 6, payload.texto || '');
            await enviarMensagemAR(client, message, ar.tipo_mensagem, payload, cfg);
        });
        
        return true;
    }
    
    return false;
}

// ============================================================================
//  EXPRESS API
// ============================================================================
const app = express();
const PORT = process.env.PORT || 3000;

// Necessário para o express-rate-limit funcionar corretamente atrás de um proxy
// (ex: XAMPP, Nginx, Apache). Ajuste o valor conforme sua infraestrutura:
//   1  → confia em um nível de proxy (recomendado para maioria dos casos)
//   'loopback' → apenas proxies locais (127.0.0.1)
app.set('trust proxy', 1);

const allowedOrigins = process.env.CORS_ORIGIN
    ? process.env.CORS_ORIGIN.split(',').map(o => o.trim())
    : ['*'];

app.use(cors({
    origin: function(origin, callback) {
        if (!origin) return callback(null, true);
        if (allowedOrigins.includes('*') || allowedOrigins.indexOf(origin) !== -1) {
            callback(null, true);
        } else {
            callback(new Error('Não permitido pela política de CORS'));
        }
    },
    methods: ['GET', 'POST'],
}));

app.use(express.json({ limit: '10mb' }));

const sessionLimiter = rateLimit({
    windowMs: 15 * 60 * 1000,
    max: 15,
    message: { error: 'Muitas requisições. Tente novamente mais tarde.' },
    standardHeaders: true,
    legacyHeaders: false,
});

const sendLimiter = rateLimit({
    windowMs: 1 * 60 * 1000,
    max: 60,
    message: { error: 'Limite de envio excedido. Aguarde um momento.' },
    standardHeaders: true,
    legacyHeaders: false,
});

function authMiddleware(req, res, next) {
    const serverToken = process.env.API_TOKEN;
    if (!serverToken) return next();
    const token = req.headers['x-api-token'];
    if (token !== serverToken) {
        return res.status(401).json({ error: 'Token inválido.' });
    }
    next();
}

// ── Healthcheck ──────────────────────────────────────────────────────────────
app.get('/', (req, res) => {
    res.json({
        app: 'Wixy Bot API',
        status: 'online',
        sessoes: sessions.size,
        uptime: process.uptime(),
        modoManutencao,
    });
});

// ── Status geral ─────────────────────────────────────────────────────────────
app.get('/status', authMiddleware, (req, res) => {
    const lista = [];
    sessions.forEach((s, id) => {
        lista.push({
            session_id: id,
            status: s.status,
            numero: s.numero || null,
            nome: s.nome || null,
        });
    });
    res.json({ status: 'online', sessoes: lista, modoManutencao });
});

// ── Status de sessão específica ───────────────────────────────────────────────
app.get('/session/status/:session_id', authMiddleware, async (req, res) => {
    const session_id = sanitizarId(req.params.session_id);
    if (!session_id) return res.status(400).json({ error: 'session_id inválido.' });
    
    const s = sessions.get(session_id);
    if (!s) {
        const dbSessao = await getSessionData(session_id);
        return res.json({
            session_id,
            status: dbSessao ? dbSessao.status : 'desconectado',
            numero: dbSessao ? dbSessao.numero : null,
            nome: dbSessao ? dbSessao.nome_conta : null,
            foto_url: dbSessao ? dbSessao.foto_url : null,
        });
    }
    
    res.json({
        session_id,
        status: s.status,
        numero: s.numero || null,
        nome: s.nome || null,
        foto_url: s.foto || null,
        qr_base64: s.qrBase64 || null,
    });
});

// ── Status em tempo real (sem DB) ─────────────────────────────────────────────
app.get('/session/realtime/:session_id', authMiddleware, (req, res) => {
    const session_id = sanitizarId(req.params.session_id);
    if (!session_id) return res.status(400).json({ error: 'session_id inválido.' });
    
    const s = sessions.get(session_id);
    res.json({
        status: s?.status || 'desconectado',
        numero: s?.numero || null,
        qr_base64: s?.qrBase64 || null,
        timestamp: Date.now()
    });
});

// ── Métricas (Observabilidade) ────────────────────────────────────────────────
app.get('/metrics', authMiddleware, (req, res) => {
    atualizarMetricasSessoes();
    const avgLatency = metrics.iaLatencia.length
        ? (metrics.iaLatencia.reduce((a,b) => a+b, 0) / metrics.iaLatencia.length).toFixed(0)
        : 0;
    const ramUsage = (process.memoryUsage().heapUsed / 1024 / 1024).toFixed(1);
    const uptimeSeconds = Math.floor((Date.now() - metrics.inicioServidor) / 1000);
    
    res.type('text/plain').send(`
# HELP wixy_messages_total Total messages processed
# TYPE wixy_messages_total counter
wixy_messages_total ${metrics.msgsProcessadas}

# HELP wixy_messages_ia_total IA messages processed
# TYPE wixy_messages_ia_total counter
wixy_messages_ia_total ${metrics.msgsIA}

# HELP wixy_messages_ar_total AR messages processed
# TYPE wixy_messages_ar_total counter
wixy_messages_ar_total ${metrics.msgsAR}

# HELP wixy_messages_failures_total Failed messages
# TYPE wixy_messages_failures_total counter
wixy_messages_failures_total ${metrics.msgsFalhas}

# HELP wixy_ia_latency_ms_avg Average IA response time
# TYPE wixy_ia_latency_ms_avg gauge
wixy_ia_latency_ms_avg ${avgLatency}

# HELP wixy_ia_failures_total IA API failures
# TYPE wixy_ia_failures_total counter
wixy_ia_failures_total ${metrics.iaFalhas}

# HELP wixy_sessions_connected Connected WhatsApp sessions
# TYPE wixy_sessions_connected gauge
wixy_sessions_connected ${metrics.sessoesConectadas}

# HELP wixy_sessions_waiting_qr Sessions waiting for QR
# TYPE wixy_sessions_waiting_qr gauge
wixy_sessions_waiting_qr ${metrics.sessoesAguardandoQR}

# HELP wixy_ram_usage_mb RAM usage in MB
# TYPE wixy_ram_usage_mb gauge
wixy_ram_usage_mb ${ramUsage}

# HELP wixy_uptime_seconds Server uptime
# TYPE wixy_uptime_seconds gauge
wixy_uptime_seconds ${uptimeSeconds}

# HELP wixy_maintenance_mode Maintenance mode status
# TYPE wixy_maintenance_mode gauge
wixy_maintenance_mode ${modoManutencao ? 1 : 0}
    `.trim());
});

// ── Iniciar sessão / Gerar QR ─────────────────────────────────────────────────
app.post('/session/start', authMiddleware, sessionLimiter, async (req, res) => {
    const session_id = sanitizarId(req.body.session_id);
    const user_id = sanitizarId(req.body.user_id);
    
    if (!session_id) {
        return res.status(400).json({ error: 'session_id é obrigatório.' });
    }
    
    let userId = user_id;
    if (!userId) {
        const dbSessao = await getSessionData(session_id);
        if (!dbSessao) {
            return res.status(404).json({ error: 'Sessão não encontrada no banco.' });
        }
        userId = dbSessao.usuario_id;
    }
    
    try {
        const db = await getDb();
        const [uRows] = await db.execute(
            'SELECT plano, desativado FROM usuarios WHERE id = ? LIMIT 1',
            [userId]
        );
        if (uRows.length) {
            const u = uRows[0];
            const MAX_SESSOES = (u.plano === 'free' || u.desativado) ? 1 : 10;
            const [countRows] = await db.execute(
                'SELECT COUNT(*) AS total FROM bot_whatsapp_sessoes WHERE usuario_id = ?',
                [userId]
            );
            const totalSessoes = countRows[0]?.total ?? 0;
            const [ownerRows] = await db.execute(
                'SELECT id FROM bot_whatsapp_sessoes WHERE session_id = ? AND usuario_id = ? LIMIT 1',
                [session_id, userId]
            );
            const jaPertence = ownerRows.length > 0;
            if (!jaPertence && totalSessoes >= MAX_SESSOES) {
                return res.status(403).json({
                    error: `Limite de ${MAX_SESSOES} sessão(ões) atingido para o seu plano.`,
                    code: 'SESSION_LIMIT_REACHED',
                    max: MAX_SESSOES,
                });
            }
        }
    } catch (limitErr) {
        console.error('[SESSION/START] Erro ao checar limite de sessões:', limitErr.message);
    }
    
    const existente = sessions.get(session_id);
    
    if (existente && existente.status === 'conectado') {
        return res.json({
            session_id,
            status: 'conectado',
            numero: existente.numero || null,
            nome: existente.nome || null,
        });
    }
    
    if (existente && existente.status === 'aguardando_qr' && existente.qrBase64) {
        return res.json({
            session_id,
            status: 'aguardando_qr',
            qr: existente.qrCode,
            qr_base64: existente.qrBase64,
        });
    }
    
    if (sessionLocks.has(session_id)) {
        return res.status(202).json({
            session_id,
            status: 'iniciando',
            message: 'Sessão em processo de inicialização. Tente novamente em alguns segundos.',
        });
    }
    
    const sessao = await iniciarSessao(session_id, userId);
    
    for (let i = 0; i < 30; i++) {
        await new Promise(r => setTimeout(r, 500));
        if (sessao.qrBase64 || sessao.status === 'conectado' || sessao.status === 'erro') break;
    }
    
    if (sessao.status === 'conectado') {
        return res.json({
            session_id,
            status: 'conectado',
            numero: sessao.numero || null,
            nome: sessao.nome || null,
        });
    }
    
    if (sessao.qrBase64) {
        return res.json({
            session_id,
            status: 'aguardando_qr',
            qr: sessao.qrCode,
            qr_base64: sessao.qrBase64,
        });
    }
    
    if (sessao.status === 'erro') {
        return res.status(500).json({
            session_id,
            status: 'erro',
            message: 'Falha ao inicializar a sessão. Tente novamente.',
        });
    }
    
    res.status(202).json({
        session_id,
        status: 'iniciando',
        message: 'Sessão iniciando, tente novamente em alguns segundos.',
    });
});

// ── Desconectar sessão ────────────────────────────────────────────────────────
app.post('/session/disconnect', authMiddleware, async (req, res) => {
    const session_id = sanitizarId(req.body.session_id);
    if (!session_id) {
        return res.status(400).json({ error: 'session_id é obrigatório.' });
    }
    
    const s = sessions.get(session_id);
    if (s) {
        sessions.delete(session_id);
        sessionLocks.delete(session_id);
        destruirCliente(s.client).finally(() => limparPastaSessao(session_id));
    }
    
    await updateSessionStatus(session_id, { status: 'desconectado' });
    atualizarMetricasSessoes();
    
    res.json({ success: true, message: 'Sessão desconectada e recursos liberados.' });
});

// ── Pairing Code (mantido igual ao original) ─────────────────────────────────
// ... [código do pairing code mantido exatamente como estava] ...
// Para economizar espaço, o endpoint /session/pairing-code permanece idêntico
// ao que você já tem no código atual.

// ── Enviar mensagem ───────────────────────────────────────────────────────────
app.post('/send', authMiddleware, sendLimiter, async (req, res) => {
    const session_id = sanitizarId(req.body.session_id);
    const number = req.body.number;
    const message = req.body.message;
    
    if (!session_id || !number || !message) {
        return res.status(400).json({ error: 'session_id, number e message são obrigatórios.' });
    }
    
    const s = sessions.get(session_id);
    if (!s || s.status !== 'conectado') {
        return res.status(400).json({ error: 'Sessão não conectada.' });
    }
    
    try {
        const cleanNumber = number.replace(/\D/g, '');
        
        let chatId;
        try {
            const numberId = await Promise.race([
                s.client.getNumberId(cleanNumber),
                new Promise((_, rej) => setTimeout(() => rej(new Error('timeout')), 8000)),
            ]);
            if (!numberId) {
                return res.status(400).json({
                    success: false,
                    error: `O número ${cleanNumber} não está registrado no WhatsApp.`,
                });
            }
            chatId = numberId._serialized;
        } catch (lidErr) {
            console.warn(`[SEND] getNumberId falhou para ${cleanNumber}, usando fallback @c.us:`, lidErr.message);
            chatId = cleanNumber + '@c.us';
        }
        
        await s.client.sendMessage(chatId, message);
        res.json({ success: true, message: 'Mensagem enviada.' });
        
    } catch (err) {
        const rawMsg = err.message || '';
        const rawStack = err.stack || '';
        const fullRaw = rawMsg + ' ' + rawStack;
        
        let friendlyError;
        if (/no lid for user/i.test(fullRaw)) {
            friendlyError = 'Número sem suporte ao protocolo atual do WhatsApp. Tente iniciar uma conversa manualmente com este número primeiro.';
        } else if (/https?:\/\//i.test(rawMsg)) {
            friendlyError = 'Falha de comunicação com o WhatsApp Web. Verifique se a sessão está ativa e tente novamente.';
        } else if (/timeout/i.test(rawMsg)) {
            friendlyError = 'Tempo limite esgotado ao enviar. Tente novamente.';
        } else if (/execution context/i.test(rawMsg)) {
            friendlyError = 'A sessão foi interrompida. Reconecte e tente novamente.';
        } else if (/invalid wid/i.test(rawMsg) || /not registered/i.test(rawMsg)) {
            friendlyError = 'Número inválido ou não registrado no WhatsApp.';
        } else {
            friendlyError = rawMsg || 'Erro desconhecido ao enviar mensagem.';
        }
        
        console.error(`[SEND] Erro ao enviar para ${number}: ${rawMsg}`);
        res.status(500).json({ success: false, error: friendlyError });
    }
});

// ── Listar sessões ────────────────────────────────────────────────────────────
app.get('/sessions', authMiddleware, async (req, res) => {
    try {
        const db = await getDb();
        const [rows] = await db.execute(
            'SELECT session_id, usuario_id, status, numero, nome_conta, foto_url, atualizado_em FROM bot_whatsapp_sessoes'
        );
        res.json({ sessoes: rows });
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

// ── Estatísticas de uso do usuário ────────────────────────────────────────────
app.get('/usage/:user_id', authMiddleware, async (req, res) => {
    const user_id = sanitizarId(req.params.user_id);
    if (!user_id) return res.status(400).json({ error: 'user_id inválido.' });
    
    try {
        const stats = await getUsageStats(user_id);
        const planoSlug = await getPlanoSlug(user_id);
        const limites = await getLimitesPlano(planoSlug);
        
        res.json({
            plano: planoSlug,
            limites,
            hoje: stats.hoje,
            ontem: stats.ontem,
            semana: stats.semana
        });
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

// ── Restaurar sessões ativas do banco ao iniciar o servidor ──────────────────
async function restaurarSessoes() {
    try {
        const db = await getDb();
        const [rows] = await db.execute(
            "SELECT session_id, usuario_id FROM bot_whatsapp_sessoes WHERE status = 'conectado'"
        );
        if (rows.length === 0) {
            console.log('[INIT] Nenhuma sessão ativa para restaurar.');
            return;
        }
        
        console.log(`\n[INIT] Restaurando ${rows.length} sessão(ões) ativa(s)...`);
        for (const row of rows) {
            await iniciarSessao(row.session_id, row.usuario_id);
            await new Promise(r => setTimeout(r, 1500));
        }
        
    } catch (err) {
        console.error('[INIT] Erro ao restaurar sessões:', err.message);
    }
}

// ============================================================================
//  CRON JOB — Limpeza periódica
// ============================================================================
const CRON_INTERVALO_MS = 5 * 60 * 1000;
const CRON_DIAS_INATIVO = 7;

async function limparSessoesInativas() {
    const inicio = Date.now();
    let fantasmasRemovidos = 0;
    let bancoRemovidas = 0;
    let pastasOrfasRemovidas = 0;
    
    limparContextoAntigo();
    
    for (const [id, s] of sessions.entries()) {
        if (s.status === 'erro' || s.status === 'desconectado') {
            console.log(`[CRON] Removendo sessão fantasma da memória: ${id} (status: ${s.status})`);
            sessions.delete(id);
            sessionLocks.delete(id);
            try { destruirCliente(s.client).catch(() => {}); } catch { /* ignora */ }
            fantasmasRemovidos++;
        }
    }
    
    const MAX_TENTATIVAS_CRON = 3;
    for (let tentativa = 1; tentativa <= MAX_TENTATIVAS_CRON; tentativa++) {
        try {
            const db = await getDb();
            
            const [semUsuario] = await db.execute(`
                SELECT s.session_id FROM bot_whatsapp_sessoes s
                LEFT JOIN usuarios u ON u.id = s.usuario_id
                WHERE s.status = 'desconectado'
                  AND u.id IS NULL
            `);
            
            const [velhas] = await db.execute(`
                SELECT session_id FROM bot_whatsapp_sessoes
                WHERE status = 'desconectado'
                  AND (
                    atualizado_em IS NULL
                    OR atualizado_em < DATE_SUB(NOW(), INTERVAL ? DAY)
                  )
            `, [CRON_DIAS_INATIVO]);
            
            const idsParaRemover = new Set([
                ...semUsuario.map(r => r.session_id),
                ...velhas.map(r => r.session_id),
            ]);
            
            for (const [id] of sessions.entries()) idsParaRemover.delete(id);
            for (const id of sessionLocks.keys()) idsParaRemover.delete(id);
            
            if (idsParaRemover.size > 0) {
                const lista = [...idsParaRemover];
                const placeholders = lista.map(() => '?').join(',');
                await db.execute(
                    `DELETE FROM bot_whatsapp_sessoes WHERE session_id IN (${placeholders})`,
                    lista
                );
                bancoRemovidas = lista.length;
                console.log(`[CRON] ${bancoRemovidas} sessão(ões) removida(s) do banco: ${lista.join(', ')}`);
                
                for (const id of lista) limparPastaSessao(id);
            }
            
            break;
            
        } catch (err) {
            resetarPoolSeNecessario(err);
            if (tentativa < MAX_TENTATIVAS_CRON) {
                const espera = 5000 * tentativa;
                console.warn(`[CRON] Erro ao limpar banco (tentativa ${tentativa}/${MAX_TENTATIVAS_CRON}): ${err.message}. Retentando em ${espera / 1000}s...`);
                await new Promise(r => setTimeout(r, espera));
            } else {
                console.error(`[CRON] Erro ao limpar banco: ${err.message}`);
            }
        }
    }
    
    const pastaBase = path.resolve('./sessions');
    if (fs.existsSync(pastaBase)) {
        let entradas;
        try { entradas = fs.readdirSync(pastaBase); } catch { entradas = []; }
        
        const sessoesAtivas = new Set(
            [...sessions.entries()]
                .filter(([, s]) => s.status === 'conectado' || s.status === 'aguardando_qr' || s.status === 'aguardando_pareamento')
                .map(([id]) => `session-${id}`)
        );
        for (const id of sessionLocks.keys()) sessoesAtivas.add(`session-${id}`);
        
        for (const entrada of entradas) {
            if (!entrada.startsWith('session-')) continue;
            if (sessoesAtivas.has(entrada)) continue;
            
            const pastaCompleta = path.join(pastaBase, entrada);
            try {
                const stat = fs.statSync(pastaCompleta);
                if (!stat.isDirectory()) continue;
                const idadeMs = Date.now() - stat.mtimeMs;
                if (idadeMs < 2 * 60 * 1000) continue;
                
                const sessionId = entrada.replace(/^session-/, '');
                console.log(`[CRON] Pasta órfã: ${entrada} (${Math.round(idadeMs / 60000)}min). Removendo...`);
                limparPastaSessao(sessionId);
                pastasOrfasRemovidas++;
            } catch { /* ignora */ }
        }
    }
    
    const duracaoMs = Date.now() - inicio;
    if (fantasmasRemovidos > 0 || bancoRemovidas > 0 || pastasOrfasRemovidas > 0) {
        console.log(`[CRON] Limpeza em ${duracaoMs}ms — memória: ${fantasmasRemovidos}, banco: ${bancoRemovidas}, disco: ${pastasOrfasRemovidas}`);
    }
}

function iniciarCronLimpeza() {
    console.log(`[CRON] Cron de limpeza iniciado (intervalo: ${CRON_INTERVALO_MS / 60000}min, inativo: ${CRON_DIAS_INATIVO}d)`);
    setTimeout(() => {
        limparSessoesInativas();
        setInterval(limparSessoesInativas, CRON_INTERVALO_MS);
    }, 60 * 1000);
}

// ── Rota administrativa: limpeza manual imediata ──────────────────────────────
app.post('/admin/cleanup', authMiddleware, async (req, res) => {
    console.log('[ADMIN] Limpeza manual solicitada.');
    limparSessoesInativas().catch(err => console.error('[ADMIN] Erro na limpeza manual:', err.message));
    res.json({ success: true, message: 'Limpeza iniciada em background. Verifique os logs para o resultado.' });
});

// ── Modo Manutenção ───────────────────────────────────────────────────────────
app.post('/admin/maintenance', authMiddleware, (req, res) => {
    modoManutencao = req.body.ativo === true;
    console.log(`[ADMIN] Modo manutenção ${modoManutencao ? 'ATIVADO' : 'DESATIVADO'}`);
    res.json({ success: true, modoManutencao });
});

// ── Restart do servidor ───────────────────────────────────────────────────────
app.post('/restart', authMiddleware, (req, res) => {
    res.json({ success: true, message: 'Reiniciando servidor...' });
    setTimeout(() => process.exit(0), 500);
});

// ============================================================================
//  START SERVER
// ============================================================================
app.listen(PORT, async () => {
    console.log('\n╔══════════════════════════════════════╗');
    console.log('║  Wixy Bot API — Iniciando [v3.0]    ║');
    console.log('╚══════════════════════════════════════╝');
    console.log(`\n🚀 API rodando na porta ${PORT}`);
    console.log(`📡 DB: ${DB.host}/${DB.database}`);
    console.log(`🔐 Auth token: ${process.env.API_TOKEN ? 'Ativado' : 'Desativado'}`);
    console.log('');
    
    await criarTabelasSeNaoExistirem();
    await restaurarSessoes();
    iniciarCronLimpeza();
});

process.on('uncaughtException', err => {
    console.error('[ERROR] Exceção não tratada:', err.message);
    resetarPoolSeNecessario(err);
});

process.on('unhandledRejection', reason => {
    const msg = reason instanceof Error ? reason.message : String(reason);
    console.error('[ERROR] Promise rejeitada sem tratamento:', msg);
    if (reason instanceof Error) resetarPoolSeNecessario(reason);
});

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);

async function shutdown() {
    console.log('\n[SHUTDOWN] Encerrando sessões...');
    const promises = [];
    sessions.forEach(s => {
        promises.push(destruirCliente(s.client));
    });
    await Promise.allSettled(promises);
    sessions.clear();
    sessionLocks.clear();
    console.log('[SHUTDOWN] Servidor encerrado.');
    process.exit(0);
}