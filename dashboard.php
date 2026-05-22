<?php
/**
 * dashboard.php — Wixy
 * Painel principal do usuário: IA, Humanização, Prompt, Mídia, WhatsApp
 */

require_once __DIR__ . '/config.php';

// Protege a rota — só usuários logados
if (empty($_SESSION['usuario_id'])) {
    redirect('/login');
}

$pdo = db();
$userId = (int) $_SESSION['usuario_id'];
$usuarioNome = $_SESSION['usuario_nome'] ?? 'Usuário';

// Sincroniza fuso horário do MySQL
try { $pdo->exec("SET time_zone = '-03:00'"); } catch (PDOException $e) {}

// ── Dados completos do plano do usuário ─────────────────────────────────────
$stmtUsuario = $pdo->prepare("
    SELECT u.plano, u.criado_em, u.desativado, u.plano_expira,
           u.avatar_url,
           p.nome AS plano_nome
    FROM usuarios u
    LEFT JOIN planos_site p ON p.id = u.plano_id
    WHERE u.id = ? LIMIT 1
");
$stmtUsuario->execute([$userId]);
$usuarioDados = $stmtUsuario->fetch(PDO::FETCH_ASSOC)
    ?: ['plano' => 'free', 'criado_em' => date('Y-m-d H:i:s'), 'desativado' => 0, 'plano_expira' => null, 'plano_nome' => null];

// Busca trial_dias da config global
$trialDias = 3;
$bonusDiasChave = 10;
try {
    $r = $pdo->query("SELECT valor FROM bot_ia_config WHERE chave = 'trial_dias' LIMIT 1")->fetch();
    if ($r) $trialDias = (int)$r['valor'];
    $rb = $pdo->query("SELECT valor FROM bot_ia_config WHERE chave = 'bonus_dias_chave' LIMIT 1")->fetch();
    if ($rb) $bonusDiasChave = (int)$rb['valor'];
} catch (PDOException $e) {}

// Calcula expiração do trial (free)
$diasDesde     = (int) floor((time() - strtotime($usuarioDados['criado_em'])) / 86400);
$trialExpiraEm = date('d/m/Y', strtotime($usuarioDados['criado_em']) + $trialDias * 86400);

// Verifica se IA está bloqueada por trial/plano
$iaTrialExpirado = false;
if (!empty($usuarioDados['desativado'])) {
    $iaTrialExpirado = true;
} elseif ($usuarioDados['plano'] === 'free') {
    $iaTrialExpirado = ($diasDesde > $trialDias);
}

// Monta label e data de vencimento para exibir na sidebar
if (!empty($usuarioDados['desativado'])) {
    $sidebarPlanoLabel  = 'Conta desativada';
    $sidebarPlanoExpira = null;
    $sidebarPlanoClass  = 'expirado';
} elseif ($usuarioDados['plano'] === 'free') {
    $sidebarPlanoLabel  = 'Plano Grátis';
    $sidebarPlanoExpira = $iaTrialExpirado ? 'Trial encerrado' : 'Vence em ' . $trialExpiraEm;
    $sidebarPlanoClass  = $iaTrialExpirado ? 'expirado' : 'trial';
} else {
    $sidebarPlanoLabel  = $usuarioDados['plano_nome'] ?? 'Plano Pago';
    if (!empty($usuarioDados['plano_expira'])) {
        $sidebarPlanoExpira = 'Vence em ' . date('d/m/Y', strtotime($usuarioDados['plano_expira']));
        $sidebarPlanoClass  = strtotime($usuarioDados['plano_expira']) < time() ? 'expirado' : 'pago';
    } else {
        $sidebarPlanoExpira = 'Acesso vitalício';
        $sidebarPlanoClass  = 'pago';
    }
}

// ============================================================================
// RESTRIÇÕES DO PLANO — lidas dinamicamente da tabela plano_limites
// ============================================================================
$isFreePlan   = ($usuarioDados['plano'] === 'free');
$planoAtual   = $usuarioDados['plano'] ?: 'free';

// Carrega limites do plano atual (fallback para free se não encontrar)
$planLimits = null;
try {
    $stmtLim = $pdo->prepare("SELECT * FROM plano_limites WHERE plano_slug = ? LIMIT 1");
    $stmtLim->execute([$planoAtual]);
    $planLimits = $stmtLim->fetch(PDO::FETCH_ASSOC) ?: null;
    // Se não encontrou registro para o plano atual e não é free, tenta free como fallback
    if (!$planLimits && $planoAtual !== 'free') {
        $stmtLim->execute(['free']);
        $planLimits = $stmtLim->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (PDOException $e) { $planLimits = null; }

// Valores padrão absolutos (fallback apenas se a tabela plano_limites não existir ainda)
// Estes valores são os máximos absolutos do sistema — o real controle vem do banco (cpanel).
$limDefaults = [
    'max_sessoes'                  => 99,
    'delay_min_max'                => 60,
    'delay_max_max'                => 60,
    'contexto_msgs_max'            => 20,
    'temperature_max'              => 2.0,
    'max_tokens_max'               => 250,
    'max_chars_max'                => 1000,
    'max_tokens_por_prompt_max'    => 50000,
    'humanizacao_basica'           => '*',
    'humanizacao_avancada'         => '*',
    'midia_tipos'                  => '*',
    'liberar_tudo'                 => 1,
    'exibir_banners'               => 1,
    'global_max_tokens'            => 250,
    'global_max_chars'             => 1000,
    'global_delay_min_max'         => 60,
    'global_delay_max_max'         => 60,
    'global_contexto_max'          => 20,
    'global_max_sessoes'           => 99,
    'global_max_tokens_por_prompt' => 50000,
];

if ($planLimits) {
    $limDefaults = array_merge($limDefaults, $planLimits);
}

$limTudo = !empty($limDefaults['liberar_tudo']);
$exibirBanners = isset($limDefaults['exibir_banners']) ? (bool)$limDefaults['exibir_banners'] : true;

// Limites máximos globais (teto absoluto configurado pelo admin)
$globalLimits = [
    'max_tokens'            => (int)($limDefaults['global_max_tokens']            ?? 250),
    'max_tokens_por_prompt' => (int)($limDefaults['global_max_tokens_por_prompt'] ?? 4096),
    'max_chars'             => (int)($limDefaults['global_max_chars']             ?? 1000),
    'delay_min'             => (int)($limDefaults['global_delay_min_max']         ?? 60),
    'delay_max'             => (int)($limDefaults['global_delay_max_max']         ?? 60),
    'contexto_msgs'         => (int)($limDefaults['global_contexto_max']          ?? 20),
    'max_sessoes'           => (int)($limDefaults['global_max_sessoes']           ?? 10),
];

// Limites numéricos efetivos (o menor entre o limite do plano e o global)
$freeLimits = [
    'contexto_msgs'         => min($globalLimits['contexto_msgs'],         $limTudo ? $globalLimits['contexto_msgs']         : (int)$limDefaults['contexto_msgs_max']),
    'delay_min'             => min($globalLimits['delay_min'],             $limTudo ? $globalLimits['delay_min']             : (int)$limDefaults['delay_min_max']),
    'delay_max'             => min($globalLimits['delay_max'],             $limTudo ? $globalLimits['delay_max']             : (int)$limDefaults['delay_max_max']),
    'temperature'           => $limTudo ? 2.0 : (float)$limDefaults['temperature_max'],
    'max_tokens'            => min($globalLimits['max_tokens'],            $limTudo ? $globalLimits['max_tokens']            : (int)$limDefaults['max_tokens_max']),
    'max_tokens_por_prompt' => min($globalLimits['max_tokens_por_prompt'], $limTudo ? $globalLimits['max_tokens_por_prompt'] : (int)$limDefaults['max_tokens_por_prompt_max']),
    'max_chars'             => min($globalLimits['max_chars'],             $limTudo ? $globalLimits['max_chars']             : (int)$limDefaults['max_chars_max']),
    'system_prompt'         => $freeLimits['max_tokens_por_prompt'],
    'max_sessoes'           => min($globalLimits['max_sessoes'],           $limTudo ? $globalLimits['max_sessoes']           : (int)$limDefaults['max_sessoes']),
];

// Toggles bloqueados (humanização básica e avançada)
$togBasicoTodos  = ['humanizar_erros','humanizar_abrev','humanizar_reticencias','humanizar_emoji','humanizar_minusc','humanizar_pontuacao'];
$togAvancTodos   = ['humanizar_girias','humanizar_hesitacao','humanizar_risada','humanizar_fragmentar','humanizar_delay_extra','humanizar_emoji_reacao','humanizar_repetir_palavra','humanizar_ne_final'];

if ($limTudo) {
    $hBasicaPermitidos  = $togBasicoTodos;
    $hAvancPermitidos   = $togAvancTodos;
} else {
    $hbRaw = $limDefaults['humanizacao_basica'] ?? '[]';
    $hBasicaPermitidos  = ($hbRaw === '*') ? $togBasicoTodos : (json_decode($hbRaw, true) ?: []);
    $haRaw = $limDefaults['humanizacao_avancada'] ?? '[]';
    $hAvancPermitidos   = ($haRaw === '*') ? $togAvancTodos  : (json_decode($haRaw, true) ?: []);
}

$freeBlockedToggles = array_values(array_diff(
    array_merge($togBasicoTodos, $togAvancTodos),
    array_merge($hBasicaPermitidos, $hAvancPermitidos)
));

// Tipos de mídia permitidos
if ($limTudo) {
    $midiaPermitidos = ['imagem','video','audio'];
} else {
    $midiaRaw = $limDefaults['midia_tipos'] ?? '["imagem"]';
    $midiaPermitidos = ($midiaRaw === '*') ? ['imagem','video','audio'] : (json_decode($midiaRaw, true) ?: ['imagem']);
}
$freeBlockedMidiaTypes = array_values(array_diff(['imagem','video','audio'], $midiaPermitidos));

// Helper: aplica limites nos campos ao salvar (backend enforcement)
$enforceFreeLimits = function(string $campo, $valor) use ($freeLimits) {
    if (!isset($freeLimits[$campo])) return $valor;
    return min((float)$valor, $freeLimits[$campo]);
};

// ============================================================================
// CRIAR / GARANTIR TABELAS DO USUÁRIO
// ============================================================================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_ia_config_usuario (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id   INT         NOT NULL,
            chave        VARCHAR(80) NOT NULL,
            valor        TEXT,
            UNIQUE KEY uq_usr_chave (usuario_id, chave),
            INDEX idx_usuario (usuario_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) {}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_ia_midias_usuario (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id    INT          NOT NULL,
            gatilho       VARCHAR(80)  NOT NULL,
            descricao     VARCHAR(255) DEFAULT NULL,
            caminho       VARCHAR(500) NOT NULL,
            tipo          ENUM('imagem','video','audio') NOT NULL DEFAULT 'imagem',
            tipo_gatilho  ENUM('direto','prompt') NOT NULL DEFAULT 'direto',
            criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_usuario (usuario_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    // Adiciona colunas se a tabela já existia sem elas
    try { $pdo->exec("ALTER TABLE bot_ia_midias_usuario MODIFY tipo ENUM('imagem','video','audio') NOT NULL DEFAULT 'imagem'"); } catch(PDOException $e){}
    try { $pdo->exec("ALTER TABLE bot_ia_midias_usuario ADD COLUMN tipo_gatilho ENUM('direto','prompt') NOT NULL DEFAULT 'direto' AFTER tipo"); } catch(PDOException $e){}
} catch (PDOException $e) {}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_whatsapp_sessoes (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id    INT          NOT NULL,
            numero        VARCHAR(30)  DEFAULT NULL,
            nome_conta    VARCHAR(120) DEFAULT NULL,
            foto_url      VARCHAR(500) DEFAULT NULL,
            status        ENUM('desconectado','aguardando_qr','conectado') NOT NULL DEFAULT 'desconectado',
            session_id    VARCHAR(64)  NOT NULL UNIQUE,
            apelido       VARCHAR(80)  DEFAULT NULL,
            atualizado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            criado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_usuario (usuario_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    // Migration: remover UNIQUE antigo em usuario_id (se existir) e adicionar colunas novas
    try { $pdo->exec("ALTER TABLE bot_whatsapp_sessoes DROP INDEX usuario_id"); } catch(PDOException $e){}
    try { $pdo->exec("ALTER TABLE bot_whatsapp_sessoes DROP INDEX uq_usuario_id"); } catch(PDOException $e){}
    try { $pdo->exec("ALTER TABLE bot_whatsapp_sessoes ADD UNIQUE KEY uq_session_id (session_id)"); } catch(PDOException $e){}
    try { $pdo->exec("ALTER TABLE bot_whatsapp_sessoes ADD COLUMN IF NOT EXISTS apelido VARCHAR(80) DEFAULT NULL"); } catch(PDOException $e){}
    try { $pdo->exec("ALTER TABLE bot_whatsapp_sessoes ADD COLUMN IF NOT EXISTS criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"); } catch(PDOException $e){}
} catch (PDOException $e) {}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_auto_respostas (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id      INT          NOT NULL,
            nome            VARCHAR(120) NOT NULL,
            gatilhos        TEXT         NOT NULL COMMENT 'JSON array de palavras-gatilho',
            ativo           TINYINT(1)   NOT NULL DEFAULT 1,
            delay_min       INT          NOT NULL DEFAULT 2,
            delay_max       INT          NOT NULL DEFAULT 6,
            tipo_mensagem   ENUM('texto','reply_buttons','list_message','cta_button','poll','product','flow','location_request','sequencial') NOT NULL DEFAULT 'texto',
            payload         LONGTEXT     NOT NULL COMMENT 'JSON com o payload completo da mensagem',
            ordem           INT          NOT NULL DEFAULT 0,
            criado_em       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_usuario (usuario_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) {}


// ============================================================================
// CRIAR TABELAS DO server2.js (bot_usage_stats, bot_event_logs)
// ============================================================================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_usage_stats (
            usuario_id    INT PRIMARY KEY,
            msgs_ia_hoje  INT DEFAULT 0,
            msgs_ar_hoje  INT DEFAULT 0,
            msgs_ia_ontem INT DEFAULT 0,
            msgs_ar_ontem INT DEFAULT 0,
            msgs_ia_semana JSON,
            msgs_ar_semana JSON,
            ultimo_reset  DATE DEFAULT (CURRENT_DATE),
            INDEX idx_usuario (usuario_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_event_logs (
            id          BIGINT AUTO_INCREMENT PRIMARY KEY,
            usuario_id  INT NOT NULL,
            session_id  VARCHAR(100),
            tipo_evento VARCHAR(50) NOT NULL,
            dados       JSON,
            criado_em   DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_usuario_data (usuario_id, criado_em),
            INDEX idx_tipo (tipo_evento)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException $e) {}

// ============================================================================
// CARREGAR USO DO DIA (rate limit por plano — server2.js)
// ============================================================================
$usageStats = ['hoje' => ['ia' => 0, 'ar' => 0], 'ontem' => ['ia' => 0, 'ar' => 0]];
$limitesMsgs = ['ia' => 50, 'ar' => 200]; // defaults free
try {
    // Limites por plano (espelha getLimitesPlano do server.js)
    $limitesMap = [
        'free'   => ['ia' => 50,    'ar' => 200],
        'mensal' => ['ia' => 2000,  'ar' => 10000],
        'anual'  => ['ia' => 10000, 'ar' => 50000],
    ];
    $limitesMsgs = $limitesMap[$planoAtual] ?? $limitesMap['free'];

    $stmtUsage = $pdo->prepare("SELECT msgs_ia_hoje, msgs_ar_hoje, msgs_ia_ontem, msgs_ar_ontem, ultimo_reset FROM bot_usage_stats WHERE usuario_id = ?");
    $stmtUsage->execute([$userId]);
    $rowUsage = $stmtUsage->fetch(PDO::FETCH_ASSOC);

    if ($rowUsage) {
        $hoje = date('Y-m-d');
        // Reset diário automático se mudou o dia
        if ($rowUsage['ultimo_reset'] !== $hoje) {
            $pdo->prepare("INSERT INTO bot_usage_stats (usuario_id, msgs_ia_hoje, msgs_ar_hoje, msgs_ia_ontem, msgs_ar_ontem, ultimo_reset)
                           VALUES (?, 0, 0, ?, ?, CURRENT_DATE)
                           ON DUPLICATE KEY UPDATE
                               msgs_ia_ontem = msgs_ia_hoje,
                               msgs_ar_ontem = msgs_ar_hoje,
                               msgs_ia_hoje  = 0,
                               msgs_ar_hoje  = 0,
                               ultimo_reset  = CURRENT_DATE")
                ->execute([$userId, $rowUsage['msgs_ia_hoje'], $rowUsage['msgs_ar_hoje']]);
            $usageStats['hoje']  = ['ia' => 0, 'ar' => 0];
            $usageStats['ontem'] = ['ia' => (int)$rowUsage['msgs_ia_hoje'], 'ar' => (int)$rowUsage['msgs_ar_hoje']];
        } else {
            $usageStats['hoje']  = ['ia' => (int)$rowUsage['msgs_ia_hoje'],  'ar' => (int)$rowUsage['msgs_ar_hoje']];
            $usageStats['ontem'] = ['ia' => (int)$rowUsage['msgs_ia_ontem'], 'ar' => (int)$rowUsage['msgs_ar_ontem']];
        }
    }
} catch (PDOException $e) {}

// ============================================================================
// VERIFICAR MODO MANUTENÇÃO DO SERVIDOR (server2.js /admin/maintenance)
// ============================================================================
$modoManutencao = false;
// AJAX: verificar status do servidor (chamado pelo JS via /session/realtime)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'server_health') {
    header('Content-Type: application/json');
    $healthUrl = (defined('NODE_API_URL') ? NODE_API_URL : 'https://api.wixy.com.br') . '/';
    $ch = curl_init($healthUrl);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['x-api-token: ' . (defined('NODE_API_KEY') ? NODE_API_KEY : '')]]);
    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = $raw ? json_decode($raw, true) : null;
    echo json_encode([
        'ok' => ($httpCode === 200),
        'modoManutencao' => (bool)($data['modoManutencao'] ?? false),
        'sessoes' => (int)($data['sessoes'] ?? 0),
        'uptime' => $data['uptime'] ?? null,
    ]);
    exit;
}

// AJAX: buscar estatísticas de uso do usuário via server2.js /usage/:id
if (isset($_GET['ajax']) && $_GET['ajax'] === 'usage_stats') {
    header('Content-Type: application/json');
    $apiBase = defined('NODE_API_URL') ? NODE_API_URL : 'https://api.wixy.com.br';
    $ch = curl_init($apiBase . '/usage/' . $userId);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['x-api-token: ' . (defined('NODE_API_KEY') ? NODE_API_KEY : '')]]);
    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200 && $raw) {
        echo $raw; // passa direto o JSON do server2.js
    } else {
        // fallback: lê do banco PHP
        echo json_encode([
            'plano'   => $planoAtual,
            'limites' => $limitesMsgs,
            'hoje'    => $usageStats['hoje'],
            'ontem'   => $usageStats['ontem'],
        ]);
    }
    exit;
}

// ============================================================================
// DEFAULTS DE CONFIG DO USUÁRIO
// ============================================================================
$configDefaults = [
    // Humanização básica
    'humanizar_erros'         => '1',
    'humanizar_abrev'         => '1',
    'humanizar_reticencias'   => '1',
    'humanizar_emoji'         => '1',
    'humanizar_minusc'        => '1',
    'humanizar_pontuacao'     => '1',
    // Humanização avançada
    'humanizar_girias'        => '1',
    'humanizar_hesitacao'     => '1',
    'humanizar_risada'        => '1',
    'humanizar_fragmentar'    => '0',
    'humanizar_delay_extra'   => '0',
    'humanizar_emoji_reacao'  => '1',
    'humanizar_repetir_palavra' => '0',
    'humanizar_ne_final'      => '1',
    // Probabilidades
    'prob_minusc'             => '35',
    'prob_pontuacao'          => '50',
    'prob_abrev'              => '25',
    'prob_reticencias'        => '20',
    'prob_erros'              => '15',
    'prob_girias'             => '20',
    'prob_hesitacao'          => '20',
    'prob_risada'             => '60',
    'prob_caps'               => '15',
    'prob_emoji_reacao'       => '25',
    'prob_ne_final'           => '20',
    // Delay humanizado
    'delay_min'               => '4',
    'delay_max'               => '14',
    // IA
    'temperature'             => '1.1',
    'max_tokens'              => '80',
    'max_chars'               => '300',
    // Prompt
    'system_prompt'           => "Você é um atendente brasileiro extremamente humano, natural, simpático e espontâneo conversando pelo WhatsApp.\n\nSeu objetivo é criar conexão, gerar confiança e ajudar o cliente de forma leve e natural.\n\nJeito de conversar:\n- Informal, direto e espontâneo\n- Escreva como alguém digitando no celular\n- Prefira respostas curtas (até 25 palavras)\n- Use poucos emojis, não em toda mensagem\n- Às vezes escreva de forma imperfeita\n\nRegras:\n- Nunca diga que é IA, bot ou assistente virtual\n- Nunca use linguagem corporativa ou formal\n- Nunca escreva mensagens longas e organizadas\n- Varie bastante as respostas\n\nSeu objetivo final é fazer o cliente sentir que está conversando com uma pessoa real, simpática e confiável.",
    // Outras configs
    'contexto_msgs'           => '15',
    'fallback_msg'            => 'oi! to aqui 😊',
    'fallback_ativo'          => '1',
    'blacklist'               => '',

    'idioma'                  => 'pt-BR',
    'modo_resposta'           => 'natural',
    'horario_inicio'          => '00:00',
    'horario_fim'             => '23:59',
    'fora_horario_msg'        => '',
    'ia_ativo'                => '1',
    'ar_ativo'                => '1',
];

// Inserir defaults apenas se não existirem
$stmtIns = $pdo->prepare("INSERT IGNORE INTO bot_ia_config_usuario (usuario_id, chave, valor) VALUES (?, ?, ?)");
foreach ($configDefaults as $k => $v) {
    $stmtIns->execute([$userId, $k, $v]);
}

// Carregar configs do usuário
$stmtCfg = $pdo->prepare("SELECT chave, valor FROM bot_ia_config_usuario WHERE usuario_id = ?");
$stmtCfg->execute([$userId]);
$cfg = [];
foreach ($stmtCfg->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $cfg[$row['chave']] = $row['valor'];
}
$cfg = array_merge($configDefaults, $cfg);

// ============================================================================
// SALVAR CONFIGURAÇÕES (POST)
// ============================================================================
$salvoMsg   = '';
$salvoErro  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {

    $acao = $_POST['acao'];

    // Prepared statement compartilhado entre as ações de salvar
    $stmtUpd = $pdo->prepare("
        INSERT INTO bot_ia_config_usuario (usuario_id, chave, valor)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE valor = VALUES(valor)
    ");

    // Helper: salva apenas os campos desta ação, sem tocar nos demais
    $salvarCampos = function(array $campos, array $booleans) use ($stmtUpd, $userId, $iaTrialExpirado, &$cfg) {
        foreach ($campos as $c) {
            $val = in_array($c, $booleans) ? (isset($_POST[$c]) ? '1' : '0') : trim($_POST[$c] ?? '');
            if ($c === 'ia_ativo' && $iaTrialExpirado) $val = '0';
            $stmtUpd->execute([$userId, $c, $val]);
            $cfg[$c] = $val;
        }
    };

    // ── Salvar Humanização ───────────────────────────────────────────────────
    if ($acao === 'salvar_humanizacao') {
        $campos = [
            'humanizar_erros','humanizar_abrev','humanizar_reticencias','humanizar_emoji',
            'humanizar_minusc','humanizar_pontuacao','humanizar_girias','humanizar_hesitacao',
            'humanizar_risada','humanizar_fragmentar',
            'humanizar_delay_extra','humanizar_emoji_reacao','humanizar_repetir_palavra','humanizar_ne_final',
            'delay_min','delay_max',
            'prob_minusc','prob_pontuacao','prob_abrev','prob_reticencias','prob_erros',
            'prob_girias','prob_hesitacao','prob_risada','prob_caps','prob_emoji_reacao','prob_ne_final',
            'contexto_msgs','fallback_msg','fallback_ativo',
        ];
        $booleans = [
            'humanizar_erros','humanizar_abrev','humanizar_reticencias','humanizar_emoji',
            'humanizar_minusc','humanizar_pontuacao','humanizar_girias','humanizar_hesitacao',
            'humanizar_risada','humanizar_fragmentar',
            'humanizar_delay_extra','humanizar_emoji_reacao','humanizar_repetir_palavra','humanizar_ne_final',
            'fallback_ativo',
        ];
        // Backend enforcement: bloquear toggles restritos no plano free
        foreach ($campos as $c) {
            $val = in_array($c, $booleans)
                ? (isset($_POST[$c]) ? '1' : '0')
                : trim($_POST[$c] ?? '');
            if ($c === 'ia_ativo' && $iaTrialExpirado) $val = '0';
            // Forçar OFF para toggles bloqueados pelo plano
            if (in_array($c, $freeBlockedToggles)) $val = '0';
            // Aplicar limites numéricos do plano
            if (in_array($c, ['delay_min','delay_max'])) $val = (string)(int)$enforceFreeLimits($c, $val);
            if ($c === 'contexto_msgs') $val = (string)(int)$enforceFreeLimits($c, $val);
            $stmtUpd->execute([$userId, $c, $val]);
            $cfg[$c] = $val;
        }
        $salvoMsg = 'Humanização salva com sucesso!';
    }

    // ── Salvar Prompt ────────────────────────────────────────────────────────
    if ($acao === 'salvar_prompt') {
        $campos   = ['system_prompt'];
        $booleans = [];
        // Backend enforcement para plano free
        foreach ($campos as $c) {
            $val = trim($_POST[$c] ?? '');
            if ($c === 'system_prompt') $val = mb_substr($val, 0, $freeLimits['system_prompt']);
            $stmtUpd->execute([$userId, $c, $val]);
            $cfg[$c] = $val;
        }
        // Salvar prompts por sessao (somente plano pago com multiplas sessoes)
        if (!$isFreePlan) {
            $promptsPost = $_POST['session_prompts'] ?? [];
            if (is_array($promptsPost)) {
                foreach ($promptsPost as $sid => $spVal) {
                    $sidClean = preg_replace('/[^a-zA-Z0-9_\-]/', '', $sid);
                    if (!$sidClean) continue;
                    $spVal = mb_substr(trim($spVal), 0, $freeLimits['max_tokens_por_prompt']);
                    $chave = 'session_prompt_' . $sidClean;
                    $stmtUpd->execute([$userId, $chave, $spVal]);
                    $cfg[$chave] = $spVal;
                }
            }
        }
        $salvoMsg = 'Prompt salvo com sucesso!';
    }

    // ── Salvar Configurações gerais ──────────────────────────────────────────
    if ($acao === 'salvar_config') {
        $campos   = [
            'temperature','max_tokens','max_chars','blacklist',
            'idioma','modo_resposta',
            'horario_inicio','horario_fim','fora_horario_msg','ia_ativo','ar_ativo',
        ];
        $booleans = ['ia_ativo','ar_ativo'];
        // Backend enforcement para plano free
        foreach ($campos as $c) {
            $val = in_array($c, $booleans)
                ? (isset($_POST[$c]) ? '1' : '0')
                : trim($_POST[$c] ?? '');
            if ($c === 'ia_ativo' && $iaTrialExpirado) $val = '0';
            if ($c === 'ar_ativo' && $iaTrialExpirado) $val = '0'; // plano vencido desativa AR também
            if (in_array($c, ['temperature','max_tokens','max_chars'])) {
                $val = (string)$enforceFreeLimits($c, $val);
            }
            $stmtUpd->execute([$userId, $c, $val]);
            $cfg[$c] = $val;
        }
        $salvoMsg = 'Configurações salvas com sucesso!';
    }

    // ── Adicionar mídia ──────────────────────────────────────────────────────
    if ($acao === 'add_midia' && isset($_FILES['arquivo'])) {
        $gatilho      = trim($_POST['gatilho'] ?? '');
        $descricao    = trim($_POST['descricao'] ?? '');
        $tipo         = $_POST['tipo'] ?? 'imagem';
        $tipo_gatilho = in_array($_POST['tipo_gatilho'] ?? '', ['direto','prompt']) ? $_POST['tipo_gatilho'] : 'direto';

        // Backend enforcement: bloquear tipos de mídia não permitidos pelo plano
        if (in_array($tipo, $freeBlockedMidiaTypes)) {
            $salvoErro = 'Upload de ' . ucfirst($tipo) . ' não está disponível no seu plano atual. Faça upgrade para um plano superior.';
        } elseif ($gatilho && $_FILES['arquivo']['error'] === UPLOAD_ERR_OK) {
            $ext     = strtolower(pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp','mp4','mov','avi','mp3','ogg','aac','m4a','wav','opus'];
            if (in_array($ext, $allowed)) {
                $dir = __DIR__ . '/uploads/midias/' . $userId . '/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $nome = uniqid('m_') . '.' . $ext;
                if (move_uploaded_file($_FILES['arquivo']['tmp_name'], $dir . $nome)) {

                    $caminho = '/uploads/midias/' . $userId . '/' . $nome;
                    $pdo->prepare("INSERT INTO bot_ia_midias_usuario (usuario_id, gatilho, descricao, caminho, tipo, tipo_gatilho) VALUES (?,?,?,?,?,?)")
                        ->execute([$userId, $gatilho, $descricao, $caminho, $tipo, $tipo_gatilho]);
                    $salvoMsg = 'Mídia cadastrada com sucesso!';
                }
            } else {
                $salvoErro = 'Formato de arquivo não permitido.';
            }
        } else {
            $salvoErro = 'Informe o gatilho e selecione um arquivo.';
        }
    }

    // ── Remover mídia ────────────────────────────────────────────────────────
    if ($acao === 'del_midia') {
        $midId = (int) ($_POST['midia_id'] ?? 0);
        if ($midId) {
            $stmtMid = $pdo->prepare("SELECT caminho FROM bot_ia_midias_usuario WHERE id = ? AND usuario_id = ?");
            $stmtMid->execute([$midId, $userId]);
            $mid = $stmtMid->fetch(PDO::FETCH_ASSOC);
            if ($mid) {
                $arquivo = __DIR__ . $mid['caminho'];
                if (file_exists($arquivo)) unlink($arquivo);
                $pdo->prepare("DELETE FROM bot_ia_midias_usuario WHERE id = ? AND usuario_id = ?")->execute([$midId, $userId]);
                $salvoMsg = 'Mídia removida.';
            }
        }
    }
}

    // ── Auto Respostas: Adicionar ────────────────────────────────────────────
    if ($acao === 'add_auto_resposta') {
        $nome         = trim($_POST['ar_nome']         ?? '');
        $gatilhos_raw = trim($_POST['ar_gatilhos']     ?? '');
        $delay_min    = max(0, min(60, (int)($_POST['ar_delay_min'] ?? 2)));
        $delay_max    = max(0, min(120,(int)($_POST['ar_delay_max'] ?? 6)));
        $tipo         = $_POST['ar_tipo_mensagem'] ?? 'texto';
        $payload_raw  = trim($_POST['ar_payload'] ?? '{}');
        $ordem        = (int)($_POST['ar_ordem'] ?? 0);

        $tipos_validos = ['texto','reply_buttons','list_message','cta_button','poll','product','flow','location_request','sequencial'];
        if (!in_array($tipo, $tipos_validos)) $tipo = 'texto';

        // Normaliza gatilhos para JSON
        $gatilhos_arr = array_values(array_filter(array_map('trim', explode(',', $gatilhos_raw))));
        $gatilhos_json = json_encode($gatilhos_arr, JSON_UNESCAPED_UNICODE);

        // Valida payload JSON
        $payload_decoded = json_decode($payload_raw, true);
        if (!$payload_decoded) $payload_raw = json_encode(['texto' => '']);

        if ($nome && count($gatilhos_arr) > 0) {
            // Verifica limite do plano free
            if ($isFreePlan) {
                $countAR = (int)$pdo->query("SELECT COUNT(*) FROM bot_auto_respostas WHERE usuario_id = {$userId}")->fetchColumn();
                if ($countAR >= 5) {
                    $salvoErro = 'Limite atingido! Usuários gratuitos podem ter no máximo 5 auto respostas. Faça upgrade para criar mais.';
                    goto fim_add_ar;
                }
            }
            $pdo->prepare("INSERT INTO bot_auto_respostas (usuario_id, nome, gatilhos, delay_min, delay_max, tipo_mensagem, payload, ordem) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$userId, $nome, $gatilhos_json, $delay_min, $delay_max, $tipo, $payload_raw, $ordem]);
            $salvoMsg = 'Auto resposta cadastrada com sucesso!';
        } else {
            $salvoErro = 'Informe um nome e pelo menos um gatilho.';
        }
        fim_add_ar:
    }

    // ── Auto Respostas: Deletar ──────────────────────────────────────────────
    if ($acao === 'del_auto_resposta') {
        $arId = (int)($_POST['ar_id'] ?? 0);
        if ($arId) {
            $pdo->prepare("DELETE FROM bot_auto_respostas WHERE id = ? AND usuario_id = ?")->execute([$arId, $userId]);
            $salvoMsg = 'Auto resposta removida.';
        }
    }

    // ── Auto Respostas: Toggle Ativo ─────────────────────────────────────────
    if ($acao === 'toggle_auto_resposta') {
        $arId = (int)($_POST['ar_id'] ?? 0);
        if ($arId) {
            $pdo->prepare("UPDATE bot_auto_respostas SET ativo = NOT ativo WHERE id = ? AND usuario_id = ?")->execute([$arId, $userId]);
            $salvoMsg = 'Status atualizado.';
        }
    }

    // ── Auto Respostas: AJAX GET (JSON) ──────────────────────────────────────
    if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_auto_respostas') {
        header('Content-Type: application/json');
        $rows = $pdo->prepare("SELECT * FROM bot_auto_respostas WHERE usuario_id = ? ORDER BY ordem ASC, criado_em DESC");
        $rows->execute([$userId]);
        echo json_encode($rows->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }


// ============================================================================
// CARREGAR MÍDIAS
// ============================================================================
$stmtMidias = $pdo->prepare("SELECT * FROM bot_ia_midias_usuario WHERE usuario_id = ? ORDER BY criado_em DESC");
$stmtMidias->execute([$userId]);
$midias = $stmtMidias->fetchAll(PDO::FETCH_ASSOC);

// ============================================================================
// CARREGAR AUTO RESPOSTAS
// ============================================================================
$stmtAR = $pdo->prepare("SELECT * FROM bot_auto_respostas WHERE usuario_id = ? ORDER BY ordem ASC, criado_em DESC");
$stmtAR->execute([$userId]);
$autoRespostas = $stmtAR->fetchAll(PDO::FETCH_ASSOC);


// ============================================================================
// MULTI-SESSÃO WHATSAPP
// ============================================================================
// Limite de sessões por tipo de plano
$maxSessoesPlano = !empty($usuarioDados['desativado']) ? 0 : $freeLimits['max_sessoes'];

// Carregar TODAS as sessões do usuário
$stmtSessoes = $pdo->prepare("SELECT * FROM bot_whatsapp_sessoes WHERE usuario_id = ? ORDER BY criado_em ASC");
$stmtSessoes->execute([$userId]);
$sessoesWA = $stmtSessoes->fetchAll(PDO::FETCH_ASSOC);

// Garantir sessão padrão se não houver nenhuma
if (empty($sessoesWA)) {
    $sessionId = 'wixy_' . $userId . '_' . bin2hex(random_bytes(8));
    $pdo->prepare("INSERT INTO bot_whatsapp_sessoes (usuario_id, session_id, status, apelido) VALUES (?,?,?,?)")
        ->execute([$userId, $sessionId, 'desconectado', 'Sessão 1']);
    $stmtSessoes->execute([$userId]);
    $sessoesWA = $stmtSessoes->fetchAll(PDO::FETCH_ASSOC);
}

// Compatibilidade retroativa: primeira sessão como $sessaoWA
$sessaoWA = $sessoesWA[0] ?? null;

// ── Ações AJAX de sessão ────────────────────────────────────────────────
// Adicionar nova sessão
if (isset($_GET['ajax']) && $_GET['ajax'] === 'add_sessao') {
    header('Content-Type: application/json');
    $totalSessoes = count($sessoesWA);
    if (!empty($usuarioDados['desativado'])) {
        echo json_encode(['ok' => false, 'erro' => 'Conta desativada.']);
        exit;
    }
    if ($totalSessoes >= $maxSessoesPlano) {
        echo json_encode(['ok' => false, 'erro' => "Limite de {$maxSessoesPlano} sessão(ões) atingido no seu plano."]);
        exit;
    }
    $novoSessionId = 'wixy_' . $userId . '_' . bin2hex(random_bytes(8));
    $apelido = 'Sessão ' . ($totalSessoes + 1);
    $pdo->prepare("INSERT INTO bot_whatsapp_sessoes (usuario_id, session_id, status, apelido) VALUES (?,?,?,?)")
        ->execute([$userId, $novoSessionId, 'desconectado', $apelido]);
    echo json_encode(['ok' => true, 'session_id' => $novoSessionId, 'apelido' => $apelido]);
    exit;
}

// Remover sessão
if (isset($_GET['ajax']) && $_GET['ajax'] === 'del_sessao') {
    header('Content-Type: application/json');
    $sidDel = trim($_GET['session_id'] ?? '');
    if (!$sidDel) { echo json_encode(['ok' => false]); exit; }
    // Não permite remover se for a única sessão
    $totalAtual = (int)$pdo->prepare("SELECT COUNT(*) FROM bot_whatsapp_sessoes WHERE usuario_id = ?")->execute([$userId]) ? 
        (int)$pdo->query("SELECT COUNT(*) FROM bot_whatsapp_sessoes WHERE usuario_id = $userId")->fetchColumn() : 1;
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM bot_whatsapp_sessoes WHERE usuario_id = ?");
    $stmtCount->execute([$userId]);
    $totalAtual = (int)$stmtCount->fetchColumn();
    if ($totalAtual <= 1) { echo json_encode(['ok' => false, 'erro' => 'Você precisa ter ao menos 1 sessão.']); exit; }
    $pdo->prepare("DELETE FROM bot_whatsapp_sessoes WHERE session_id = ? AND usuario_id = ?")->execute([$sidDel, $userId]);
    echo json_encode(['ok' => true]);
    exit;
}

// Renomear sessão (apelido)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'rename_sessao') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $sidRen = trim($input['session_id'] ?? '');
    $novoApelido = mb_substr(trim($input['apelido'] ?? ''), 0, 80);
    if (!$sidRen || !$novoApelido) { echo json_encode(['ok' => false]); exit; }
    $pdo->prepare("UPDATE bot_whatsapp_sessoes SET apelido = ? WHERE session_id = ? AND usuario_id = ?")
        ->execute([$novoApelido, $sidRen, $userId]);
    echo json_encode(['ok' => true]);
    exit;
}

// ============================================================================
// TABELA: chaves groq enviadas por usuários
// ============================================================================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS usuario_groq_keys (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id   INT          NOT NULL,
            api_key      VARCHAR(255) NOT NULL,
            key_masked   VARCHAR(30)  NOT NULL,
            dias_ganhos  INT          NOT NULL DEFAULT 10,
            criado_em    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_usuario (usuario_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) {}

// Quantas chaves o usuário já inseriu (para exibição)
$groqKeysUsuario = [];
try {
    $stmtGK = $pdo->prepare("SELECT * FROM usuario_groq_keys WHERE usuario_id = ? ORDER BY criado_em DESC");
    $stmtGK->execute([$userId]);
    $groqKeysUsuario = $stmtGK->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Total de dias ganhos pelas chaves cadastradas
$totalDiasGanhos = 0;
foreach ($groqKeysUsuario as $gk) {
    $totalDiasGanhos += (int)$gk['dias_ganhos'];
}

// ── AJAX: Validar e salvar chave Groq ────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'enviar_groq_key') {
    header('Content-Type: application/json');
    $input   = json_decode(file_get_contents('php://input'), true);
    $apiKey  = trim($input['api_key'] ?? '');

    if (!$apiKey) {
        echo json_encode(['ok' => false, 'erro' => 'Informe a chave da API.']);
        exit;
    }

    // Verifica se essa chave já foi cadastrada por este usuário
    $stmtExist = $pdo->prepare("SELECT id FROM usuario_groq_keys WHERE usuario_id = ? AND api_key = ?");
    $stmtExist->execute([$userId, $apiKey]);
    if ($stmtExist->fetch()) {
        echo json_encode(['ok' => false, 'erro' => 'Você já cadastrou esta chave anteriormente.']);
        exit;
    }

    // Verifica cooldown de 10 dias desde a última chave cadastrada pelo usuário
    $stmtCooldown = $pdo->prepare("SELECT criado_em FROM usuario_groq_keys WHERE usuario_id = ? ORDER BY criado_em DESC LIMIT 1");
    $stmtCooldown->execute([$userId]);
    $ultimaChave = $stmtCooldown->fetch(PDO::FETCH_ASSOC);
    if ($ultimaChave) {
        $diasDesdeUltima = (time() - strtotime($ultimaChave['criado_em'])) / 86400;
        if ($diasDesdeUltima < 10) {
            $diasRestantes = ceil(10 - $diasDesdeUltima);
            echo json_encode(['ok' => false, 'erro' => 'Você só pode cadastrar uma nova chave a cada 10 dias. Aguarde mais ' . $diasRestantes . ' dia' . ($diasRestantes > 1 ? 's' : '') . '.']);
            exit;
        }
    }

    // Verifica se essa chave já existe no sistema (outro usuário)
    $stmtGlobal = $pdo->prepare("SELECT id FROM usuario_groq_keys WHERE api_key = ?");
    $stmtGlobal->execute([$apiKey]);
    if ($stmtGlobal->fetch()) {
        echo json_encode(['ok' => false, 'erro' => 'Esta chave já foi cadastrada no sistema.']);
        exit;
    }

    // Validar a chave na API do Groq
    $modelo  = 'llama-3.1-8b-instant';
    $payload = json_encode(['model' => $modelo, 'max_tokens' => 5, 'messages' => [['role' => 'user', 'content' => 'oi']]]);
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        echo json_encode(['ok' => false, 'erro' => 'Erro de conexão ao validar: ' . $err]);
        exit;
    }
    if ($code === 401) {
        echo json_encode(['ok' => false, 'erro' => 'Chave inválida ou expirada. Verifique se copiou corretamente.']);
        exit;
    }
    if ($code !== 200) {
        echo json_encode(['ok' => false, 'erro' => 'Erro ao validar a chave (HTTP ' . $code . '). Tente novamente.']);
        exit;
    }

    // Chave válida! Salvar na tabela de chaves do usuário
    $keyMasked = strlen($apiKey) > 10 ? substr($apiKey, 0, 6) . '...' . substr($apiKey, -4) : '****';
    $pdo->prepare("INSERT INTO usuario_groq_keys (usuario_id, api_key, key_masked, dias_ganhos) VALUES (?, ?, ?, ?)")
        ->execute([$userId, $apiKey, $keyMasked, $bonusDiasChave]);

    // Inserir a chave no Gerenciador de Chaves (bot_ia_config.api_keys)
    try {
        $cfgKeys = $pdo->query("SELECT valor FROM bot_ia_config WHERE chave = 'api_keys' LIMIT 1")->fetch();
        $keysArr = $cfgKeys ? json_decode($cfgKeys['valor'], true) : [];
        if (!is_array($keysArr)) $keysArr = [];

        // Próximo número de ordem
        $maxOrdem = 0;
        foreach ($keysArr as $k) { if (isset($k['ordem']) && (int)$k['ordem'] > $maxOrdem) $maxOrdem = (int)$k['ordem']; }
        $novaOrdem = $maxOrdem + 1;

        $keysArr[] = [
            'label'      => 'Usuário #' . $userId . ' — ' . $keyMasked,
            'key'        => $apiKey,
            'provider'   => 'groq',
            'url'        => 'https://api.groq.com/openai/v1/chat/completions',
            'model'      => 'todos',
            'ordem'      => $novaOrdem,
            'origem'     => 'usuario',
            'usuario_id' => $userId,
        ];

        $pdo->prepare("INSERT INTO bot_ia_config (chave, valor) VALUES ('api_keys', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_em = NOW()")
            ->execute([json_encode($keysArr, JSON_UNESCAPED_UNICODE)]);
    } catch (PDOException $e) { /* silencioso */ }

    // Somar dias de bônus ao plano do usuário (trial ou plano_expira)
    try {
        if ($usuarioDados['plano'] === 'free') {
            // Para plano free: aumenta os trial_dias globais não funciona por usuário
            // então inserimos uma config pessoal com dias extras
            $stmtExtra = $pdo->prepare("SELECT valor FROM bot_ia_config_usuario WHERE usuario_id = ? AND chave = 'trial_dias_extra' LIMIT 1");
            $stmtExtra->execute([$userId]);
            $extraRow = $stmtExtra->fetch();
            $diasExtra = $extraRow ? (int)$extraRow['valor'] : 0;
            $diasExtra += $bonusDiasChave;
            $pdo->prepare("INSERT INTO bot_ia_config_usuario (usuario_id, chave, valor) VALUES (?, 'trial_dias_extra', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
                ->execute([$userId, $diasExtra]);
        } else {
            // Para plano pago: extende plano_expira
            $expiraAtual = $usuarioDados['plano_expira'] ?? null;
            $base = ($expiraAtual && strtotime($expiraAtual) > time()) ? strtotime($expiraAtual) : time();
            $novaExpira = date('Y-m-d H:i:s', $base + $bonusDiasChave * 86400);
            $pdo->prepare("UPDATE usuarios SET plano_expira = ? WHERE id = ?")->execute([$novaExpira, $userId]);
        }
    } catch (PDOException $e) { /* silencioso */ }

    echo json_encode(['ok' => true, 'key_masked' => $keyMasked, 'dias_ganhos' => $bonusDiasChave]);
    exit;
}

// ── Calcula dias totais disponíveis (trial + extras) ─────────────────────────
$trialDiasExtra = 0;
try {
    $stmtEx = $pdo->prepare("SELECT valor FROM bot_ia_config_usuario WHERE usuario_id = ? AND chave = 'trial_dias_extra' LIMIT 1");
    $stmtEx->execute([$userId]);
    $rowEx = $stmtEx->fetch();
    if ($rowEx) $trialDiasExtra = (int)$rowEx['valor'];
} catch (PDOException $e) {}

$trialDiasTotal = $trialDias + $trialDiasExtra;
// Recalcula expiração com os dias extras
$trialExpiraEm = date('d/m/Y', strtotime($usuarioDados['criado_em']) + $trialDiasTotal * 86400);
$iaTrialExpirado = false;
if (!empty($usuarioDados['desativado'])) {
    $iaTrialExpirado = true;
} elseif ($usuarioDados['plano'] === 'free') {
    $iaTrialExpirado = ($diasDesde > $trialDiasTotal);
}
// Recalcula sidebar com os dias corretos
if ($usuarioDados['plano'] === 'free') {
    $sidebarPlanoExpira = $iaTrialExpirado ? 'Trial encerrado' : 'Vence em ' . $trialExpiraEm;
    $sidebarPlanoClass  = $iaTrialExpirado ? 'expirado' : 'trial';
}

// ============================================================================
// ABA ATIVA
// ============================================================================
$aba = $_GET['aba'] ?? 'whatsapp';
$abasValidas = ['whatsapp','humanizacao','prompt','midia','auto_respostas','config','groq_key'];
if (!in_array($aba, $abasValidas)) $aba = 'whatsapp';

// ============================================================================
// SEO
// ============================================================================
$seo_title       = 'Dashboard - ' . APP_NAME;
$seo_description = 'Gerencie sua IA, WhatsApp e configurações do bot.';
$seo_robots      = 'noindex, nofollow';

// Títulos das abas (atualizado com nova aba)
$titulos = [
    'whatsapp'    => 'WhatsApp',
    'humanizacao' => 'Humanização',
    'prompt'      => 'Prompt',
    'midia'       => 'Mídia IA',
    'auto_respostas' => 'Auto Respostas',
    'groq_key'    => 'Ganhar Dias',
    'config'      => 'Configurações',
];
?>
<?php require_once __DIR__ . '/header.php'; ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     DASHBOARD
════════════════════════════════════════════════════════════════════════════ -->
<div class="dash-root<?= !$exibirBanners ? ' dash-no-banners' : '' ?>">

    <!-- ── Sidebar ─────────────────────────────────────────────────────────── -->
    <aside class="dash-sidebar" id="dashSidebar" role="navigation" aria-label="Menu do painel">

        <div class="dash-sidebar__logo" aria-hidden="true">
            <div class="dash-sidebar__logo-icon">
                <svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" fill="none">
                    <path d="M2 4 L5.5 14 L10 7 L14.5 14 L18 4" stroke="white" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <span class="dash-sidebar__logo-name">Wi<em>xy</em></span>
        </div>

        <nav class="dash-nav">
            <a href="?aba=whatsapp" class="dash-nav__item dash-nav__item--wa <?= $aba === 'whatsapp' ? 'active' : '' ?>">
                <span class="dash-nav__wa-icon">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="currentColor" width="20" height="20" aria-hidden="true">
                        <path d="M12.001 2C6.478 2 2.001 6.477 2.001 12c0 1.821.487 3.53 1.338 5.014L2.001 22l5.135-1.312A9.959 9.959 0 0012.001 22c5.523 0 10-4.477 10-10S17.524 2 12.001 2zm0 1.5A8.5 8.5 0 0120.5 12a8.5 8.5 0 01-8.499 8.5 8.46 8.46 0 01-4.402-1.232l-.314-.19-3.05.779.808-2.959-.208-.326A8.459 8.459 0 013.5 12 8.5 8.5 0 0112.001 3.5zM8.757 7.564c-.193 0-.506.072-.772.358-.265.287-1.012.99-1.012 2.41s1.036 2.797 1.18 2.99c.145.193 2.012 3.172 4.93 4.326 2.441.964 2.919.773 3.444.724.524-.048 1.69-.691 1.928-1.36.239-.668.239-1.24.168-1.36-.072-.12-.264-.193-.553-.337-.288-.145-1.69-.836-1.952-.932-.264-.096-.456-.144-.649.144-.192.289-.745.932-.912 1.125-.168.193-.336.217-.625.072-.288-.144-1.217-.448-2.319-1.43-.857-.765-1.437-1.71-1.605-1.999-.168-.288-.018-.444.126-.588.13-.13.289-.337.433-.505.145-.168.193-.289.289-.481.096-.193.048-.361-.024-.506-.072-.144-.632-1.571-.877-2.148-.228-.545-.463-.484-.649-.493l-.55-.01z"/>
                    </svg>
                </span>
                <span>WhatsApp</span>
                <?php
                $waBadge = 'red';
                $waTitle = 'Desconectado';
                foreach ($sessoesWA as $_s) {
                    if ($_s['status'] === 'conectado')    { $waBadge = 'green';  $waTitle = 'Conectado'; break; }
                    if ($_s['status'] === 'aguardando_qr') { $waBadge = 'yellow'; $waTitle = 'Aguardando QR'; }
                }
                ?>
                <span class="dash-nav__badge dash-nav__badge--<?= $waBadge ?>" title="<?= $waTitle ?>"></span>
            </a>
            <a href="?aba=humanizacao" class="dash-nav__item <?= $aba === 'humanizacao' ? 'active' : '' ?>">
                <i class="ph ph-smiley-wink"></i>
                <span>Humanização</span>
            </a>
            <a href="?aba=prompt"      class="dash-nav__item <?= $aba === 'prompt'      ? 'active' : '' ?>">
                <i class="ph ph-chat-teardrop-text"></i>
                <span>Prompt</span>
            </a>
            <a href="?aba=midia"       class="dash-nav__item <?= $aba === 'midia'       ? 'active' : '' ?>">
                <i class="ph ph-image-square"></i>
                <span>Mídia IA</span>
            </a>
            <a href="?aba=auto_respostas" class="dash-nav__item <?= $aba === 'auto_respostas' ? 'active' : '' ?>">
                <i class="ph ph-robot"></i>
                <span>Auto Respostas</span>
            </a>
            <a href="?aba=config"      class="dash-nav__item <?= $aba === 'config'      ? 'active' : '' ?>">
                <i class="ph ph-sliders-horizontal"></i>
                <span>Configurações</span>
            </a>
        </nav>

        <div class="dash-sidebar__extra">
            <a href="?aba=groq_key" class="dash-nav__item dash-nav__item--groq <?= $aba === 'groq_key' ? 'active' : '' ?>">
                <i class="ph ph-gift"></i>
                <span>Ganhar Dias</span>
                <span class="dash-nav__badge dash-nav__badge--green" title="Ganhe +<?= $bonusDiasChave ?> dias grátis"></span>
            </a>
        </div>

        <div class="dash-sidebar__footer">
            <div class="dash-sidebar__user">
                <div class="dash-sidebar__avatar" aria-hidden="true">
                    <?php
                    $avatarUrl = $usuarioDados['avatar_url'] ?? '';
                    if ($avatarUrl && str_starts_with($avatarUrl, 'data:image')):
                    ?>
                        <img src="<?= e($avatarUrl) ?>" alt="Avatar" class="dash-sidebar__avatar-img">
                    <?php else: ?>
                        <?= mb_strtoupper(mb_substr($usuarioNome, 0, 1)) ?>
                    <?php endif; ?>
                </div>
                <div class="dash-sidebar__user-info">
                    <span class="dash-sidebar__user-name"><?= e($usuarioNome) ?></span>
                    <span class="dash-sidebar__user-plan dash-sidebar__user-plan--<?= $sidebarPlanoClass ?>">
                        <?= e($sidebarPlanoLabel) ?>
                    </span>
                    <?php if ($sidebarPlanoExpira): ?>
                    <span class="dash-sidebar__user-expira"><?= e($sidebarPlanoExpira) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <a href="/logout" class="dash-sidebar__logout" title="Sair" onclick="return confirm('Deseja sair?')">
                <i class="ph ph-sign-out"></i>
            </a>
        </div>
    </aside>

    <!-- ── Conteúdo principal ──────────────────────────────────────────────── -->
    <main class="dash-main" id="dashMain">

        <!-- Topbar mobile -->
        <div class="dash-topbar">
            <button class="dash-topbar__toggle" id="sidebarToggle" aria-label="Abrir menu">
                <i class="ph ph-list"></i>
            </button>
            <span class="dash-topbar__title">
                <?php
                echo e($titulos[$aba] ?? 'Dashboard');
                ?>
            </span>
            <div class="dash-topbar__status">
                <?php
                $algumConectado = array_filter($sessoesWA, fn($s) => $s['status'] === 'conectado');
                if ($algumConectado): ?>
                    <span class="status-dot status-dot--green"></span>
                    <span><?= count($algumConectado) ?> conectado<?= count($algumConectado) > 1 ? 's' : '' ?></span>
                <?php else: ?>
                    <span class="status-dot status-dot--red"></span>
                    <span>Desconectado</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Alertas ── -->
        <?php if ($salvoMsg): ?>
            <div class="dash-alert dash-alert--success" role="alert">
                <i class="ph ph-check-circle"></i>
                <?= e($salvoMsg) ?>
            </div>
        <?php endif; ?>
        <?php if ($salvoErro): ?>
            <div class="dash-alert dash-alert--error" role="alert">
                <i class="ph ph-x-circle"></i>
                <?= e($salvoErro) ?>
            </div>
        <?php endif; ?>

        <!-- ── Banner Plano Free ── -->
        <?php if ($isFreePlan && $exibirBanners): ?>
        <div class="dash-free-banner" role="alert">
            <div class="dash-free-banner__icon">
                <i class="ph ph-crown-simple"></i>
            </div>
            <div class="dash-free-banner__content">
                <strong>Você está no Plano Grátis</strong>
                <span>Algumas funções estão limitadas ou bloqueadas. Faça upgrade para desbloquear o potencial completo da Wixy.</span>
            </div>
            <a href="/planos" class="dash-free-banner__cta">
                <i class="ph ph-rocket-launch"></i>
                Ver Planos
            </a>
        </div>
        <?php endif; ?>

        <?php if ($aba === 'whatsapp'): ?>
        <div class="dash-section">

            <div class="dash-section__header">
                <h1 class="dash-section__title">
                    <i class="ph ph-whatsapp-logo"></i>
                    Sessões WhatsApp
                </h1>
                <p class="dash-section__sub">
                    Conecte e gerencie suas contas de WhatsApp. Cada sessão representa um número diferente.
                </p>
            </div>

            <?php if ($isFreePlan && $exibirBanners): ?>
            <!-- Banner multi-sessão bloqueada -->
            <div class="ms-upgrade-banner">
                <div class="ms-upgrade-banner__icon"><i class="ph ph-devices"></i></div>
                <div class="ms-upgrade-banner__content">
                    <strong>Multi-Sessão disponível no plano pago</strong>
                    <span>Com um plano pago você pode conectar até <strong>10 contas de WhatsApp</strong> diferentes, cada uma com sua própria IA e configurações.</span>
                </div>
                <a href="/planos" class="ms-upgrade-banner__cta">
                    <i class="ph ph-rocket-launch"></i>
                    Ver Planos
                </a>
            </div>
            <?php else: ?>
            <!-- Cabeçalho multi-sessão (pago) -->
            <div class="ms-header-bar">
                <div class="ms-header-bar__info">
                    <span class="ms-header-bar__count">
                        <i class="ph ph-devices"></i>
                        <strong><?= count($sessoesWA) ?></strong> / <?= $maxSessoesPlano ?> sessões ativas
                    </span>
                    <div class="ms-header-bar__slots">
                        <?php for ($s = 0; $s < $maxSessoesPlano; $s++): ?>
                        <span class="ms-slot ms-slot--<?= $s < count($sessoesWA) ? 'used' : 'free' ?>"></span>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php if (count($sessoesWA) < $maxSessoesPlano): ?>
                <button class="btn-dash btn-dash--primary ms-add-btn" id="btnAddSessao" onclick="adicionarSessao()">
                    <i class="ph ph-plus"></i>
                    Nova Sessão
                </button>
                <?php else: ?>
                <span class="ms-limit-reached"><i class="ph ph-check-square"></i> Limite de <?= $maxSessoesPlano ?> sessões atingido</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Grid de cards de sessão -->
            <div class="ms-sessions-grid" id="msSessionsGrid">
                <?php foreach ($sessoesWA as $idx => $sessao): ?>
                <?php
                    $sId       = $sessao['session_id'];
                    $sSt       = $sessao['status'];
                    $sApelido  = $sessao['apelido'] ?? ('Sessão ' . ($idx + 1));
                    $sConectado = $sSt === 'conectado';
                    $sAguardando = $sSt === 'aguardando_qr';
                ?>
                <div class="ms-session-card <?= $sConectado ? 'ms-session-card--connected' : ($sAguardando ? 'ms-session-card--waiting' : '') ?>"
                     id="msCard_<?= e($sId) ?>" data-session-id="<?= e($sId) ?>">

                    <!-- Cabeçalho do card -->
                    <div class="ms-card__header">
                        <div class="ms-card__title-wrap">
                            <span class="ms-card__status-dot ms-dot--<?= $sConectado ? 'green' : ($sAguardando ? 'yellow' : 'red') ?>"></span>
                            <span class="ms-card__apelido" id="msApelido_<?= e($sId) ?>"><?= e($sApelido) ?></span>
                            <button class="ms-card__rename-btn" title="Renomear" onclick="renomearSessao('<?= e($sId) ?>')">
                                <i class="ph ph-pencil-simple"></i>
                            </button>
                        </div>
                        <?php if (!$isFreePlan && count($sessoesWA) > 1): ?>
                        <button class="ms-card__del-btn" title="Remover sessão" onclick="removerSessao('<?= e($sId) ?>')">
                            <i class="ph ph-trash"></i>
                        </button>
                        <?php endif; ?>
                    </div>

                    <!-- Corpo do card -->
                    <div class="ms-card__body">
                        <?php if ($sConectado): ?>
                        <!-- CONECTADO -->
                        <div class="ms-card__connected">
                            <?php if (!empty($sessao['foto_url']) && $sessao['foto_url'] !== 'Sem foto'): ?>
                                <img src="<?= e($sessao['foto_url']) ?>" alt="Avatar" class="ms-card__avatar">
                            <?php else: ?>
                                <div class="ms-card__avatar ms-card__avatar--fallback">
                                    <?= mb_strtoupper(mb_substr($sessao['nome_conta'] ?? 'W', 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                            <div class="ms-card__account">
                                <span class="ms-card__name"><?= e($sessao['nome_conta'] ?? 'Sem nome') ?></span>
                                <span class="ms-card__number"><i class="ph ph-phone"></i> +<?= e($sessao['numero'] ?? '') ?></span>
                                <span class="ms-card__badge ms-badge--connected"><i class="ph ph-check-circle"></i> Conectado</span>
                            </div>
                        </div>
                        <div class="ms-card__actions">
                            <button class="btn-dash btn-dash--danger btn-dash--sm" onclick="desconectarWA('<?= e($sId) ?>', this)">
                                <i class="ph ph-plug-charging"></i> Desconectar
                            </button>
                        </div>
                        <?php elseif ($sAguardando): ?>
                        <!-- AGUARDANDO QR / PAREAMENTO -->
                        <div class="ms-connect-mode" id="msConnectMode_<?= e($sId) ?>">
                            <button class="ms-mode-btn ms-mode-btn--active" id="msModeQr_<?= e($sId) ?>" onclick="setModoConexao('<?= e($sId) ?>', 'qr')">
                                <i class="ph ph-qr-code"></i> QR Code
                            </button>
                            <button class="ms-mode-btn" id="msModePairing_<?= e($sId) ?>" onclick="setModoConexao('<?= e($sId) ?>', 'pairing')">
                                <i class="ph ph-device-mobile"></i> Código
                            </button>
                        </div>
                        <div id="msQrBlock_<?= e($sId) ?>">
                            <div class="ms-qr-area" id="msQrArea_<?= e($sId) ?>">
                                <div class="ms-qr-loading" id="msQrLoading_<?= e($sId) ?>" style="display:none;">
                                    <div class="ms-qr-spinner"></div>
                                    <p>Gerando QR…</p>
                                </div>
                                <div class="ms-qr-placeholder" id="msQrPlaceholder_<?= e($sId) ?>">
                                    <i class="ph ph-clock"></i>
                                    <p>Aguardando QR Code…</p>
                                </div>
                                <div id="msQrImage_<?= e($sId) ?>" style="display:none; text-align:center;">
                                    <div id="msQrImgWrap_<?= e($sId) ?>"></div>
                                    <div class="ms-qr-timer"><i class="ph ph-clock"></i> QR válido por <span id="msQrCount_<?= e($sId) ?>">60</span>s</div>
                                </div>
                            </div>
                            <div class="ms-card__actions ms-card__actions--center">
                                <button class="btn-dash btn-dash--primary btn-dash--sm" onclick="gerarQrCode('<?= e($sId) ?>', this)">
                                    <i class="ph ph-qr-code"></i> Gerar QR Code
                                </button>
                            </div>
                        </div>
                        <div id="msPairingBlock_<?= e($sId) ?>" style="display:none;">
                            <div class="ms-pairing-area" id="msPairingArea_<?= e($sId) ?>">
                                <div class="ms-pairing-input-wrap">
                                    <label class="ms-pairing-label"><i class="ph ph-phone"></i> Número do WhatsApp</label>
                                    <div class="ms-phone-row">
                                        <select class="ms-country-select" id="msPairingCountry_<?= e($sId) ?>" onchange="msFmtPhone('<?= e($sId) ?>')">
                                        </select>
                                        <input type="tel" class="ms-phone-input ms-pairing-input" id="msPairingPhone_<?= e($sId) ?>"
                                               placeholder="(11) 99999-8888" maxlength="15"
                                               oninput="msFmtPhone('<?= e($sId) ?>')">
                                    </div>
                                    <span class="ms-pairing-hint">Digite o número sem o código do país</span>
                                    <div class="ms-pairing-attempt" id="msPairingAttempt_<?= e($sId) ?>" style="display:none;"></div>
                                </div>
                                <div class="ms-pairing-code-display" id="msPairingCodeDisplay_<?= e($sId) ?>" style="display:none;">
                                    <span class="ms-pairing-code-label"><i class="ph ph-key"></i> Código de pareamento</span>
                                    <div class="ms-pairing-code" id="msPairingCode_<?= e($sId) ?>">----</div>
                                    <div class="ms-pairing-attempts-bar" id="msPairingAttemptsBar_<?= e($sId) ?>">
                                        <span class="ms-pairing-attempts-label">Tentativas:</span>
                                        <span class="ms-pairing-dot ms-pairing-dot--active" id="msPairingDot1_<?= e($sId) ?>"></span>
                                        <span class="ms-pairing-dot" id="msPairingDot2_<?= e($sId) ?>"></span>
                                        <span class="ms-pairing-dot" id="msPairingDot3_<?= e($sId) ?>"></span>
                                    </div>
                                    <p class="ms-pairing-instructions">No WhatsApp do celular:<br><strong>Configurações → Dispositivos vinculados → Vincular dispositivo → Usar número de telefone</strong></p>
                                    <div class="ms-pairing-status" id="msPairingStatus_<?= e($sId) ?>">
                                        <div class="ms-qr-spinner" style="width:16px;height:16px;border-width:2px;"></div>
                                        <span>Aguardando confirmação…</span>
                                    </div>
                                </div>
                            </div>
                            <div class="ms-card__actions ms-card__actions--center">
                                <button class="btn-dash btn-dash--primary btn-dash--sm" id="msPairingBtn_<?= e($sId) ?>" onclick="gerarPairingCode('<?= e($sId) ?>', this)">
                                    <i class="ph ph-device-mobile"></i> Gerar Código
                                </button>
                            </div>
                        </div>

                        <?php else: ?>
                        <!-- DESCONECTADO -->
                        <div class="ms-connect-mode" id="msConnectMode_<?= e($sId) ?>">
                            <button class="ms-mode-btn ms-mode-btn--active" id="msModeQr_<?= e($sId) ?>" onclick="setModoConexao('<?= e($sId) ?>', 'qr')">
                                <i class="ph ph-qr-code"></i> QR Code
                            </button>
                            <button class="ms-mode-btn" id="msModePairing_<?= e($sId) ?>" onclick="setModoConexao('<?= e($sId) ?>', 'pairing')">
                                <i class="ph ph-device-mobile"></i> Código
                            </button>
                        </div>
                        <div id="msQrBlock_<?= e($sId) ?>">
                            <div class="ms-qr-area" id="msQrArea_<?= e($sId) ?>">
                                <div class="ms-qr-loading" id="msQrLoading_<?= e($sId) ?>" style="display:none;">
                                    <div class="ms-qr-spinner"></div>
                                    <p>Gerando QR…</p>
                                </div>
                                <div class="ms-qr-placeholder" id="msQrPlaceholder_<?= e($sId) ?>">
                                    <i class="ph ph-qr-code"></i>
                                    <p>Clique em <strong>Gerar QR Code</strong></p>
                                </div>
                                <div id="msQrImage_<?= e($sId) ?>" style="display:none; text-align:center;">
                                    <div id="msQrImgWrap_<?= e($sId) ?>"></div>
                                    <div class="ms-qr-timer"><i class="ph ph-clock"></i> QR válido por <span id="msQrCount_<?= e($sId) ?>">60</span>s</div>
                                </div>
                            </div>
                            <div class="ms-card__actions ms-card__actions--center">
                                <button class="btn-dash btn-dash--primary btn-dash--sm" onclick="gerarQrCode('<?= e($sId) ?>', this)">
                                    <i class="ph ph-qr-code"></i> Gerar QR Code
                                </button>
                            </div>
                        </div>
                        <div id="msPairingBlock_<?= e($sId) ?>" style="display:none;">
                            <div class="ms-pairing-area" id="msPairingArea_<?= e($sId) ?>">
                                <div class="ms-pairing-input-wrap">
                                    <label class="ms-pairing-label"><i class="ph ph-phone"></i> Número do WhatsApp</label>
                                    <div class="ms-phone-row">
                                        <select class="ms-country-select" id="msPairingCountry_<?= e($sId) ?>" onchange="msFmtPhone('<?= e($sId) ?>')">
                                        </select>
                                        <input type="tel" class="ms-phone-input ms-pairing-input" id="msPairingPhone_<?= e($sId) ?>"
                                               placeholder="(11) 99999-8888" maxlength="15"
                                               oninput="msFmtPhone('<?= e($sId) ?>')">
                                    </div>
                                    <span class="ms-pairing-hint">Digite o número sem o código do país</span>
                                    <div class="ms-pairing-attempt" id="msPairingAttempt_<?= e($sId) ?>" style="display:none;"></div>
                                </div>
                                <div class="ms-pairing-code-display" id="msPairingCodeDisplay_<?= e($sId) ?>" style="display:none;">
                                    <span class="ms-pairing-code-label"><i class="ph ph-key"></i> Código de pareamento</span>
                                    <div class="ms-pairing-code" id="msPairingCode_<?= e($sId) ?>">----</div>
                                    <div class="ms-pairing-attempts-bar" id="msPairingAttemptsBar_<?= e($sId) ?>">
                                        <span class="ms-pairing-attempts-label">Tentativas:</span>
                                        <span class="ms-pairing-dot ms-pairing-dot--active" id="msPairingDot1_<?= e($sId) ?>"></span>
                                        <span class="ms-pairing-dot" id="msPairingDot2_<?= e($sId) ?>"></span>
                                        <span class="ms-pairing-dot" id="msPairingDot3_<?= e($sId) ?>"></span>
                                    </div>
                                    <p class="ms-pairing-instructions">No WhatsApp do celular:<br><strong>Configurações → Dispositivos vinculados → Vincular dispositivo → Usar número de telefone</strong></p>
                                    <div class="ms-pairing-status" id="msPairingStatus_<?= e($sId) ?>">
                                        <div class="ms-qr-spinner" style="width:16px;height:16px;border-width:2px;"></div>
                                        <span>Aguardando confirmação…</span>
                                    </div>
                                </div>
                            </div>
                            <div class="ms-card__actions ms-card__actions--center">
                                <button class="btn-dash btn-dash--primary btn-dash--sm" id="msPairingBtn_<?= e($sId) ?>" onclick="gerarPairingCode('<?= e($sId) ?>', this)">
                                    <i class="ph ph-device-mobile"></i> Gerar Código
                                </button>
                            </div>
                        </div>

                        <?php endif; ?>
                    </div>

                    <!-- Rodapé do card -->
                    <div class="ms-card__footer">
                        <code class="ms-card__sid" title="Session ID"><?= e(substr($sId, 0, 32)) ?>…</code>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if ($isFreePlan): ?>
                <!-- Sessões bloqueadas (preview) para plano free -->
                <?php for ($bl = 0; $bl < 3; $bl++): ?>
                <div class="ms-session-card ms-session-card--locked">
                    <div class="ms-card__locked-overlay">
                        <div class="ms-card__locked-icon"><i class="ph ph-lock-simple"></i></div>
                        <span class="ms-card__locked-label">Sessão <?= $bl + 2 ?></span>
                        <span class="ms-card__locked-sub">Plano Pago</span>
                        <a href="/planos" class="ms-card__locked-cta">Desbloquear</a>
                    </div>
                </div>
                <?php endfor; ?>
                <?php endif; ?>
            </div>

            <!-- ── Passo a passo de conexão ───────────────────────────────────── -->
            <div class="wa-howto">
                <div class="wa-howto__header">
                    <div class="wa-howto__header-icon"><i class="ph ph-info"></i></div>
                    <div>
                        <h2 class="wa-howto__title">Como conectar seu WhatsApp</h2>
                        <p class="wa-howto__sub">Siga os passos abaixo e seu bot estará ativo em menos de 1 minuto</p>
                    </div>
                </div>

                <div class="wa-howto__steps">

                    <div class="wa-howto__step">
                        <div class="wa-howto__step-num">1</div>
                        <div class="wa-howto__step-body">
                            <div class="wa-howto__step-icon"><i class="ph ph-qr-code"></i></div>
                            <div class="wa-howto__step-text">
                                <strong>Clique em "Gerar QR Code"</strong>
                                <span>No card da sessão acima, pressione o botão verde para gerar seu QR Code de conexão.</span>
                            </div>
                        </div>
                    </div>

                    <div class="wa-howto__step-arrow"><i class="ph ph-arrow-right"></i></div>

                    <div class="wa-howto__step">
                        <div class="wa-howto__step-num">2</div>
                        <div class="wa-howto__step-body">
                            <div class="wa-howto__step-icon"><i class="ph ph-device-mobile"></i></div>
                            <div class="wa-howto__step-text">
                                <strong>Abra o WhatsApp no celular</strong>
                                <span>Toque nos 3 pontos (⋮) no canto superior direito e selecione <em>Dispositivos conectados</em>.</span>
                            </div>
                        </div>
                    </div>

                    <div class="wa-howto__step-arrow"><i class="ph ph-arrow-right"></i></div>

                    <div class="wa-howto__step">
                        <div class="wa-howto__step-num">3</div>
                        <div class="wa-howto__step-body">
                            <div class="wa-howto__step-icon"><i class="ph ph-scan"></i></div>
                            <div class="wa-howto__step-text">
                                <strong>Escanear o QR Code</strong>
                                <span>Toque em <em>Conectar dispositivo</em> e aponte a câmera para o QR Code exibido na tela.</span>
                            </div>
                        </div>
                    </div>

                    <div class="wa-howto__step-arrow"><i class="ph ph-arrow-right"></i></div>

                    <div class="wa-howto__step wa-howto__step--success">
                        <div class="wa-howto__step-num wa-howto__step-num--done"><i class="ph ph-check"></i></div>
                        <div class="wa-howto__step-body">
                            <div class="wa-howto__step-icon"><i class="ph ph-robot"></i></div>
                            <div class="wa-howto__step-text">
                                <strong>Bot ativo e respondendo!</strong>
                                <span>Sua sessão ficará verde e a IA começará a responder as mensagens automaticamente.</span>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="wa-howto__tip">
                    <i class="ph ph-lightbulb"></i>
                    <span><strong>Dica:</strong> O QR Code expira em 60 segundos. Se expirar, clique em "Gerar QR Code" novamente para gerar um novo.</span>
                </div>
            </div>

            <!-- ── Painel de Uso Diário (server2.js rate limit) ────────────────── -->
            <div class="wa-usage-panel" id="waUsagePanel">
                <div class="wa-usage-panel__header">
                    <div class="wa-usage-panel__title">
                        <i class="ph ph-chart-bar"></i>
                        Consumo do Dia
                    </div>
                    <span class="wa-usage-panel__plano"><?= e(ucfirst($planoAtual)) ?></span>
                    <button class="wa-usage-panel__refresh" onclick="refreshUsageStats()" title="Atualizar estatísticas">
                        <i class="ph ph-arrows-clockwise" id="usageRefreshIcon"></i>
                    </button>
                </div>
                <div class="wa-usage-panel__body" id="waUsagePanelBody">
                    <?php
                    $pctIA = $limitesMsgs['ia'] > 0 ? min(100, round($usageStats['hoje']['ia'] / $limitesMsgs['ia'] * 100)) : 0;
                    $pctAR = $limitesMsgs['ar'] > 0 ? min(100, round($usageStats['hoje']['ar'] / $limitesMsgs['ar'] * 100)) : 0;
                    $clsIA = $pctIA >= 90 ? 'danger' : ($pctIA >= 70 ? 'warn' : 'ok');
                    $clsAR = $pctAR >= 90 ? 'danger' : ($pctAR >= 70 ? 'warn' : 'ok');
                    ?>
                    <div class="wa-usage-metric">
                        <div class="wa-usage-metric__labels">
                            <span><i class="ph ph-robot"></i> Mensagens IA</span>
                            <span class="wa-usage-metric__nums"><?= number_format($usageStats['hoje']['ia']) ?> / <?= number_format($limitesMsgs['ia']) ?></span>
                        </div>
                        <div class="wa-usage-bar">
                            <div class="wa-usage-bar__fill wa-usage-bar__fill--<?= $clsIA ?>" style="width:<?= $pctIA ?>%"></div>
                        </div>
                        <?php if ($usageStats['ontem']['ia'] > 0): ?>
                        <span class="wa-usage-metric__yest">Ontem: <?= number_format($usageStats['ontem']['ia']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="wa-usage-metric">
                        <div class="wa-usage-metric__labels">
                            <span><i class="ph ph-lightning"></i> Auto Respostas</span>
                            <span class="wa-usage-metric__nums"><?= number_format($usageStats['hoje']['ar']) ?> / <?= number_format($limitesMsgs['ar']) ?></span>
                        </div>
                        <div class="wa-usage-bar">
                            <div class="wa-usage-bar__fill wa-usage-bar__fill--<?= $clsAR ?>" style="width:<?= $pctAR ?>%"></div>
                        </div>
                        <?php if ($usageStats['ontem']['ar'] > 0): ?>
                        <span class="wa-usage-metric__yest">Ontem: <?= number_format($usageStats['ontem']['ar']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($pctIA >= 90 || $pctAR >= 90): ?>
                <div class="wa-usage-panel__alert">
                    <i class="ph ph-warning-circle"></i>
                    Você está próximo do limite diário.
                    <?php if ($isFreePlan): ?><a href="/planos">Faça upgrade</a> para aumentar.<?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Banner Modo Manutenção (server2.js /admin/maintenance) ─── -->
            <div class="wa-maintenance-banner" id="waMaintenanceBanner" style="display:none;">
                <i class="ph ph-wrench"></i>
                <div>
                    <strong>Sistema em Manutenção</strong>
                    <span>O servidor de WhatsApp está temporariamente indisponível. Novas conexões serão possíveis em breve.</span>
                </div>
            </div>

            <!-- Info lateral -->
            <div class="ms-info-row">
                <div class="wa-info-card">
                    <div class="wa-info-card__icon"><i class="ph ph-shield-check"></i></div>
                    <div>
                        <h3>Sessões Independentes</h3>
                        <p>Cada sessão tem seu próprio número, QR code e configurações de IA.</p>
                    </div>
                </div>
                <div class="wa-info-card">
                    <div class="wa-info-card__icon"><i class="ph ph-device-mobile"></i></div>
                    <div>
                        <h3>Como escanear?</h3>
                        <p>No WhatsApp vá em <strong>Configurações › Dispositivos conectados › Conectar dispositivo</strong>.</p>
                    </div>
                </div>
                <div class="wa-info-card">
                    <div class="wa-info-card__icon"><i class="ph ph-robot"></i></div>
                    <div>
                        <h3>IA por Sessão</h3>
                        <p>Cada número conectado responde automaticamente com a IA configurada.</p>
                    </div>
                </div>
                <div class="wa-info-card">
                    <div class="wa-info-card__icon"><i class="ph ph-clock-clockwise"></i></div>
                    <div>
                        <h3>Reconexão Automática</h3>
                        <p>Se a sessão cair, o sistema tenta reconectar automaticamente sem perder o histórico.</p>
                    </div>
                </div>
                <div class="wa-info-card">
                    <div class="wa-info-card__icon"><i class="ph ph-lock-key"></i></div>
                    <div>
                        <h3>Segurança Total</h3>
                        <p>Sua sessão é criptografada e isolada. Nenhuma mensagem é armazenada em nossos servidores.</p>
                    </div>
                </div>
            </div>

        </div><!-- /.dash-section -->

        <style>
        /* ══════════════════════════════════════════════════════
           MULTI-SESSÃO — SISTEMA PREMIUM
        ══════════════════════════════════════════════════════ */

        /* Banner upgrade (free) */
        .ms-upgrade-banner {
            display: flex; align-items: center; gap: 16px;
            background: linear-gradient(135deg, #0f1f30 0%, #0a2540 100%);
            border: 1px solid #1e3a5f; border-radius: var(--dash-radius);
            padding: 20px 24px; margin-bottom: 24px;
            animation: fadeUp .4s ease both;
        }
        .ms-upgrade-banner__icon {
            width: 52px; height: 52px; border-radius: 14px;
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 1.5rem; flex-shrink: 0;
            box-shadow: 0 4px 16px rgba(99,102,241,.4);
        }
        .ms-upgrade-banner__content {
            flex: 1; display: flex; flex-direction: column; gap: 4px;
        }
        .ms-upgrade-banner__content strong { color: #e2e8f0; font-size: .9rem; }
        .ms-upgrade-banner__content span   { color: #94a3b8; font-size: .82rem; line-height: 1.5; }
        .ms-upgrade-banner__content strong { color: #93c5fd; }
        .ms-upgrade-banner__cta {
            display: inline-flex; align-items: center; gap: 7px;
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            color: #fff; font-size: .82rem; font-weight: 700;
            padding: 10px 20px; border-radius: 10px; text-decoration: none;
            white-space: nowrap; transition: filter .18s, transform .12s;
            box-shadow: 0 3px 12px rgba(99,102,241,.4);
        }
        .ms-upgrade-banner__cta:hover { filter: brightness(1.1); transform: translateY(-1px); }
        @media(max-width:640px){
            .ms-upgrade-banner { flex-wrap: wrap; }
            .ms-upgrade-banner__cta { width: 100%; justify-content: center; }
        }

        /* ══════════════════════════════════════════════════════
           PASSO A PASSO DE CONEXÃO
        ══════════════════════════════════════════════════════ */
        .wa-howto {
            background: var(--dash-surface);
            border: 1px solid var(--dash-border);
            border-radius: var(--dash-radius);
            padding: 28px 28px 22px;
            margin-top: 24px;
            box-shadow: var(--dash-shadow);
        }

        .wa-howto__header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 24px;
        }

        .wa-howto__header-icon {
            width: 40px; height: 40px; flex-shrink: 0;
            background: var(--dash-accent-dim);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: var(--dash-accent-dk);
            font-size: 1.25rem;
        }

        .wa-howto__title {
            font-family: var(--font-display, sans-serif);
            font-size: 1rem;
            font-weight: 800;
            color: var(--dash-text);
            margin: 0 0 3px;
        }

        .wa-howto__sub {
            font-size: .8125rem;
            color: var(--dash-muted);
            margin: 0;
        }

        .wa-howto__steps {
            display: flex;
            align-items: center;
            gap: 0;
            flex-wrap: wrap;
            row-gap: 12px;
        }

        .wa-howto__step {
            flex: 1;
            min-width: 160px;
            background: var(--dash-bg);
            border: 1px solid var(--dash-border);
            border-radius: 12px;
            padding: 16px 14px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            position: relative;
            transition: box-shadow .2s, border-color .2s;
        }

        .wa-howto__step:hover {
            border-color: var(--dash-accent);
            box-shadow: 0 0 0 3px var(--dash-accent-dim);
        }

        .wa-howto__step--success {
            border-color: rgba(34,197,94,.4);
            background: rgba(34,197,94,.07);
        }

        .wa-howto__step--success:hover {
            border-color: #22c55e;
            box-shadow: 0 0 0 3px rgba(34,197,94,.12);
        }

        .wa-howto__step-num {
            width: 26px; height: 26px;
            border-radius: 50%;
            background: var(--dash-accent);
            color: #fff;
            font-size: .75rem;
            font-weight: 800;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            align-self: flex-start;
        }

        .wa-howto__step-num--done {
            background: #22c55e;
        }

        .wa-howto__step-body {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .wa-howto__step-icon {
            font-size: 1.6rem;
            color: var(--dash-accent-dk);
            line-height: 1;
        }

        .wa-howto__step--success .wa-howto__step-icon {
            color: #15803d;
        }

        .wa-howto__step-text {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .wa-howto__step-text strong {
            font-size: .8375rem;
            font-weight: 700;
            color: var(--dash-text);
            line-height: 1.3;
        }

        .wa-howto__step-text span {
            font-size: .775rem;
            color: var(--dash-muted);
            line-height: 1.5;
        }

        .wa-howto__step-arrow {
            color: var(--dash-border);
            font-size: 1.2rem;
            flex-shrink: 0;
            padding: 0 6px;
            align-self: center;
        }

        .wa-howto__tip {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: rgba(245,158,11,.08);
            border: 1px solid rgba(245,158,11,.3);
            border-radius: 10px;
            padding: 11px 15px;
            margin-top: 18px;
            font-size: .8rem;
            color: var(--dash-text);
            line-height: 1.5;
        }

        .wa-howto__tip i {
            font-size: 1rem;
            color: #f59e0b;
            flex-shrink: 0;
            margin-top: 1px;
        }

        @media (max-width: 768px) {
            .wa-howto__steps {
                flex-direction: column;
                align-items: stretch;
            }
            .wa-howto__step-arrow {
                transform: rotate(90deg);
                align-self: center;
                padding: 0;
            }
            .wa-howto { padding: 20px 16px 16px; }
        }

        /* Barra de cabeçalho (pago) */
        .ms-header-bar {
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
            background: var(--dash-surface); border: 1px solid var(--dash-border);
            border-radius: var(--dash-radius); padding: 14px 20px; margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .ms-header-bar__info { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .ms-header-bar__count {
            display: flex; align-items: center; gap: 7px;
            font-size: .875rem; color: var(--dash-text); font-weight: 600;
        }
        .ms-header-bar__count i { color: var(--dash-accent); font-size: 1rem; }
        .ms-header-bar__count strong { color: var(--dash-accent-dk); font-size: 1rem; }
        .ms-header-bar__slots { display: flex; gap: 4px; }
        .ms-slot {
            width: 12px; height: 12px; border-radius: 3px;
            transition: background .2s;
        }
        .ms-slot--used { background: var(--dash-accent); box-shadow: 0 0 0 1px rgba(0,192,96,.3); }
        .ms-slot--free { background: var(--dash-border); }
        .ms-add-btn { padding: 9px 18px !important; font-size: .84rem !important; }
        .ms-limit-reached {
            display: flex; align-items: center; gap: 6px;
            font-size: .8rem; color: var(--dash-accent-dk); font-weight: 600;
        }

        /* Grid de sessões */
        .ms-sessions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }
        @media(max-width:640px){ .ms-sessions-grid { grid-template-columns: 1fr; } }

        /* Card de sessão */
        .ms-session-card {
            background: var(--dash-surface);
            border: 1.5px solid var(--dash-border);
            border-radius: 16px; overflow: hidden;
            display: flex; flex-direction: column;
            transition: box-shadow .22s, border-color .22s, transform .22s;
            animation: fadeUp .4s cubic-bezier(.34,1.56,.64,1) both;
        }
        .ms-session-card:hover { box-shadow: 0 6px 24px rgba(0,0,0,.1); transform: translateY(-2px); }
        .ms-session-card--connected {
            border-color: rgba(34,197,94,.45);
            box-shadow: 0 0 0 1px rgba(34,197,94,.12), 0 4px 16px rgba(34,197,94,.1);
        }
        .ms-session-card--waiting {
            border-color: rgba(245,158,11,.45);
        }
        .ms-session-card--locked {
            border-color: transparent;
            background: repeating-linear-gradient(
                45deg,
                rgba(0,0,0,.015),
                rgba(0,0,0,.015) 5px,
                transparent 5px,
                transparent 10px
            );
            border: 1.5px dashed var(--dash-border);
            position: relative;
        }

        /* Cabeçalho do card */
        .ms-card__header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 16px 10px;
            border-bottom: 1px solid var(--dash-border);
            background: var(--dash-bg);
        }
        .ms-card__title-wrap {
            display: flex; align-items: center; gap: 8px; min-width: 0; flex: 1;
        }
        .ms-card__status-dot {
            width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0;
        }
        .ms-dot--green  { background: #22c55e; box-shadow: 0 0 0 2.5px rgba(34,197,94,.25); }
        .ms-dot--yellow { background: #f59e0b; box-shadow: 0 0 0 2.5px rgba(245,158,11,.25); }
        .ms-dot--red    { background: #ef4444; box-shadow: 0 0 0 2.5px rgba(239,68,68,.18); }
        .ms-card__apelido {
            font-size: .875rem; font-weight: 700; color: var(--dash-text);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .ms-card__rename-btn {
            background: none; border: none; cursor: pointer; color: var(--dash-muted);
            font-size: .9rem; padding: 2px; border-radius: 5px;
            display: flex; align-items: center;
            transition: color .15s, background .15s;
        }
        .ms-card__rename-btn:hover { color: var(--dash-accent-dk); background: var(--dash-accent-dim); }
        .ms-card__del-btn {
            background: none; border: none; cursor: pointer;
            color: var(--dash-muted); font-size: .95rem; padding: 4px 6px;
            border-radius: 7px; display: flex; align-items: center;
            transition: color .15s, background .15s;
        }
        .ms-card__del-btn:hover { color: var(--color-danger); background: rgba(229,62,62,.1); }

        /* Corpo do card */
        .ms-card__body {
            flex: 1; display: flex; flex-direction: column;
            padding: 18px 16px 12px; gap: 14px;
        }

        /* Estado: conectado */
        .ms-card__connected {
            display: flex; align-items: center; gap: 14px;
            background: rgba(34,197,94,.08);
            border: 1px solid rgba(34,197,94,.2);
            border-radius: 12px; padding: 14px;
        }
        .ms-card__avatar {
            width: 48px; height: 48px; border-radius: 50%;
            object-fit: cover; flex-shrink: 0;
            border: 2px solid rgba(34,197,94,.3);
        }
        .ms-card__avatar--fallback {
            background: var(--dash-accent); color: #fff;
            font-weight: 700; font-size: 1.1rem;
            display: flex; align-items: center; justify-content: center;
        }
        .ms-card__account { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
        .ms-card__name {
            font-size: .875rem; font-weight: 700; color: var(--dash-text);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .ms-card__number {
            display: flex; align-items: center; gap: 4px;
            font-size: .78rem; color: var(--dash-muted);
        }
        .ms-card__badge {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: .68rem; font-weight: 700; padding: 2px 8px;
            border-radius: 20px; width: fit-content;
        }
        .ms-badge--connected { background: rgba(34,197,94,.12); color: #22c55e; border: 1px solid rgba(34,197,94,.3); }

        /* QR área interna ao card */
        .ms-qr-area {
            display: flex; flex-direction: column; align-items: center;
            min-height: 130px; justify-content: center;
        }
        .ms-qr-placeholder {
            display: flex; flex-direction: column; align-items: center;
            gap: 8px; color: var(--dash-muted);
            font-size: .78rem; text-align: center;
        }
        .ms-qr-placeholder i { font-size: 2.2rem; color: var(--dash-border); }
        .ms-qr-loading {
            display: flex; flex-direction: column; align-items: center;
            gap: 10px; color: var(--dash-muted); font-size: .78rem;
        }
        .ms-qr-spinner {
            width: 30px; height: 30px;
            border: 3px solid var(--dash-border);
            border-top-color: var(--dash-accent);
            border-radius: 50%;
            animation: spin .8s linear infinite;
        }
        .ms-qr-timer {
            display: flex; align-items: center; gap: 5px;
            font-size: .75rem; color: var(--color-warning); font-weight: 600;
            margin-top: 6px;
        }


        /* ── Seletor de modo de conexão (QR / Pairing Code) ─────── */
        .ms-connect-mode {
            display: flex; gap: 6px; margin-bottom: 10px;
            background: var(--dash-surface2, rgba(255,255,255,.04));
            border-radius: 8px; padding: 4px;
        }
        .ms-mode-btn {
            flex: 1; padding: 6px 8px; border-radius: 6px;
            border: 1.5px solid var(--dash-border);
            background: transparent; color: var(--dash-muted);
            font-size: .75rem; font-weight: 600; cursor: pointer;
            transition: all .2s; display: flex; align-items: center;
            justify-content: center; gap: 5px;
        }
        .ms-mode-btn:hover:not(.ms-mode-btn--active) {
            border-color: var(--dash-accent);
            color: var(--dash-accent-dk);
        }
        .ms-mode-btn--active {
            background: var(--dash-accent); color: #fff;
            border-color: var(--dash-accent);
            box-shadow: 0 2px 8px rgba(0,192,96,.3);
        }

        /* ── Área de Pairing Code ───────────────────────────────── */
        .ms-pairing-area {
            display: flex; flex-direction: column; gap: 10px;
            padding: 4px 0;
        }
        .ms-pairing-input-wrap {
            display: flex; flex-direction: column; gap: 4px;
        }
        .ms-pairing-label {
            font-size: .75rem; font-weight: 600; color: var(--dash-muted);
            display: flex; align-items: center; gap: 5px;
        }

        /* ── Phone row: select + input ── */
        .ms-phone-row {
            display: flex; gap: 6px; align-items: stretch;
        }
        .ms-country-select { display: none; } /* escondido — substituído pelo custom dropdown */

        /* ── Custom country dropdown ── */
        .ms-country-btn {
            flex: 0 0 auto; display: flex; align-items: center; gap: 5px;
            padding: 8px 8px; border-radius: 8px; min-width: 82px;
            border: 1px solid var(--dash-border); background: var(--dash-bg);
            color: var(--dash-text); font-size: .78rem; font-weight: 600;
            cursor: pointer; transition: border-color .2s; position: relative;
            white-space: nowrap; user-select: none;
        }
        .ms-country-btn:focus, .ms-country-btn.open { border-color: var(--dash-accent); outline: none; }
        .ms-country-btn img.ms-flag { width: 20px; height: 14px; border-radius: 2px; object-fit: cover; flex-shrink: 0; }
        .ms-country-btn .ms-ddi { font-size: .72rem; color: var(--dash-muted); }
        .ms-country-btn .ms-caret { margin-left: 2px; font-size: .6rem; color: var(--dash-muted); transition: transform .2s; }
        .ms-country-btn.open .ms-caret { transform: rotate(180deg); }

        .ms-country-dropdown {
            position: fixed; z-index: 99999;
            min-width: 220px; max-height: 220px; overflow-y: auto;
            background: var(--dash-surface, var(--dash-bg)); border: 1px solid var(--dash-border);
            border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.25);
            padding: 4px 0; display: none;
        }
        .ms-country-dropdown.open { display: block; }
        .ms-country-option {
            display: flex; align-items: center; gap: 8px;
            padding: 7px 12px; cursor: pointer; font-size: .82rem;
            color: var(--dash-text); transition: background .15s;
        }
        .ms-country-option:hover, .ms-country-option.selected { background: rgba(99,102,241,.12); }
        .ms-country-option img.ms-flag { width: 22px; height: 15px; border-radius: 2px; object-fit: cover; flex-shrink: 0; }
        .ms-country-option .ms-opt-name { flex: 1; }
        .ms-country-option .ms-opt-ddi { font-size: .72rem; color: var(--dash-muted); }

        .ms-pairing-input {
            width: 100%; padding: 8px 12px; border-radius: 8px;
            border: 1px solid var(--dash-border); background: var(--dash-bg);
            color: var(--dash-text); font-size: .85rem; outline: none;
            transition: border-color .2s; box-sizing: border-box;
        }
        .ms-phone-input { flex: 1; min-width: 0; }
        .ms-pairing-input:focus { border-color: var(--dash-accent); }
        .ms-pairing-hint {
            font-size: .7rem; color: var(--dash-muted); margin-top: 1px;
        }
        .ms-pairing-attempt {
            font-size: .7rem; color: var(--color-warning); font-weight: 600;
            margin-top: 2px;
        }

        /* ── Attempt dots ── */
        .ms-pairing-attempts-bar {
            display: flex; align-items: center; gap: 5px;
            font-size: .7rem; color: var(--dash-muted);
        }
        .ms-pairing-attempts-label { font-weight: 600; }
        .ms-pairing-dot {
            width: 10px; height: 10px; border-radius: 50%;
            background: var(--dash-border);
            transition: background .3s, box-shadow .3s;
        }
        .ms-pairing-dot--active {
            background: var(--dash-accent);
            box-shadow: 0 0 0 3px rgba(0,192,96,.2);
        }
        .ms-pairing-dot--used {
            background: var(--dash-muted);
        }

        /* Retry button inline */
        .ms-pairing-retry-btn {
            background: none; border: none; color: var(--dash-accent-dk);
            font-size: .72rem; font-weight: 700; cursor: pointer;
            text-decoration: underline; padding: 0; margin-left: 4px;
        }

        @keyframes pairingCodePulse {
            0%   { opacity: 0; transform: scale(.9); }
            60%  { opacity: 1; transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        .ms-pairing-code-display {
            display: flex; flex-direction: column; align-items: center;
            gap: 6px; padding: 10px; border-radius: 10px;
            background: rgba(99,102,241,.07); border: 1px solid rgba(99,102,241,.2);
            text-align: center;
        }
        .ms-pairing-code-label {
            font-size: .72rem; color: var(--dash-muted); font-weight: 600;
            display: flex; align-items: center; gap: 5px;
        }
        .ms-pairing-code {
            font-size: 1.9rem; font-weight: 800; letter-spacing: .15em;
            color: var(--dash-accent); font-family: monospace;
            background: var(--dash-bg); padding: 8px 18px;
            border-radius: 8px; border: 2px dashed var(--dash-accent);
        }
        .ms-pairing-instructions {
            font-size: .72rem; color: var(--dash-muted); margin: 0;
            line-height: 1.5;
        }
        .ms-pairing-instructions strong { color: var(--dash-text); }
        .ms-pairing-status {
            display: flex; align-items: center; gap: 6px;
            font-size: .72rem; color: var(--dash-muted);
        }

        /* Ações dos cards */
        .ms-card__actions {
            display: flex; gap: 8px; flex-wrap: wrap;
        }
        .ms-card__actions--center {
            justify-content: center;
            padding: 4px 0 8px;
        }
        .btn-dash--sm { padding: 7px 14px !important; font-size: .8rem !important; }

        /* Rodapé do card */
        .ms-card__footer {
            padding: 8px 16px;
            border-top: 1px solid var(--dash-border);
            background: var(--dash-bg);
        }
        .ms-card__sid {
            font-family: monospace; font-size: .68rem;
            color: var(--dash-muted); word-break: break-all;
        }

        /* Card bloqueado (free preview) */
        .ms-card__locked-overlay {
            display: flex; flex-direction: column; align-items: center;
            justify-content: center; gap: 8px;
            padding: 32px 20px; text-align: center;
            min-height: 200px;
        }
        .ms-card__locked-icon {
            width: 48px; height: 48px; border-radius: 14px;
            background: var(--dash-surface); border: 1px solid var(--dash-border);
            display: flex; align-items: center; justify-content: center;
            color: var(--dash-muted); font-size: 1.3rem;
        }
        .ms-card__locked-label { font-size: .875rem; font-weight: 700; color: var(--dash-muted); }
        .ms-card__locked-sub { font-size: .75rem; color: #94a3b8; }
        .ms-card__locked-cta {
            display: inline-flex; align-items: center; gap: 5px;
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            color: #fff; font-size: .75rem; font-weight: 700;
            padding: 6px 14px; border-radius: 8px; text-decoration: none;
            transition: filter .18s;
        }
        .ms-card__locked-cta:hover { filter: brightness(1.1); }

        /* Info row abaixo do howto */
        .ms-info-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-top: 24px;
        }

        /* Primeira linha: 2 cards centralizados */
        .ms-info-row .wa-info-card:nth-child(1),
        .ms-info-row .wa-info-card:nth-child(2) {
            grid-column: span 1;
        }

        /* Wrap: 2 primeiros em linha própria de 2 cols, depois 3 */
        @supports (grid-template-columns: subgrid) {
            .ms-info-row {
                grid-template-columns: repeat(6, 1fr);
            }
            .ms-info-row .wa-info-card:nth-child(1) { grid-column: 1 / 4; }
            .ms-info-row .wa-info-card:nth-child(2) { grid-column: 4 / 7; }
            .ms-info-row .wa-info-card:nth-child(3) { grid-column: 1 / 3; }
            .ms-info-row .wa-info-card:nth-child(4) { grid-column: 3 / 5; }
            .ms-info-row .wa-info-card:nth-child(5) { grid-column: 5 / 7; }
        }

        @media (max-width: 900px) {
            .ms-info-row { grid-template-columns: repeat(2, 1fr) !important; }
            .ms-info-row .wa-info-card { grid-column: span 1 !important; }
        }

        @media (max-width: 540px) {
            .ms-info-row { grid-template-columns: 1fr !important; }
        }

        /* ══════════════════════════════════════════════════════
           PAINEL DE USO DIÁRIO (server2.js rate limit)
        ══════════════════════════════════════════════════════ */
        .wa-usage-panel {
            background: var(--dash-surface);
            border: 1px solid var(--dash-border);
            border-radius: var(--dash-radius);
            padding: 18px 20px;
            margin-top: 24px;
            margin-bottom: 20px;
            box-shadow: var(--dash-shadow);
            animation: fadeUp .4s ease both;
        }
        .wa-usage-panel__header {
            display: flex; align-items: center; gap: 10px; margin-bottom: 16px;
        }
        .wa-usage-panel__title {
            display: flex; align-items: center; gap: 7px;
            font-size: .875rem; font-weight: 700; color: var(--dash-text); flex: 1;
        }
        .wa-usage-panel__title i { color: var(--dash-accent); font-size: 1rem; }
        .wa-usage-panel__plano {
            font-size: .72rem; font-weight: 700; padding: 2px 10px;
            border-radius: 20px; text-transform: capitalize;
            background: var(--dash-accent-dim); color: var(--dash-accent-dk);
            border: 1px solid rgba(0,192,96,.2);
        }
        .wa-usage-panel__refresh {
            background: none; border: none; cursor: pointer;
            color: var(--dash-muted); font-size: 1rem; padding: 4px;
            border-radius: 6px; display: flex; align-items: center;
            transition: color .15s, background .15s;
        }
        .wa-usage-panel__refresh:hover { color: var(--dash-accent-dk); background: var(--dash-accent-dim); }
        .wa-usage-panel__body { display: flex; flex-direction: column; gap: 14px; }
        .wa-usage-metric { display: flex; flex-direction: column; gap: 5px; }
        .wa-usage-metric__labels {
            display: flex; justify-content: space-between; align-items: center;
            font-size: .78rem; color: var(--dash-muted);
        }
        .wa-usage-metric__labels span:first-child {
            display: flex; align-items: center; gap: 5px; font-weight: 600;
        }
        .wa-usage-metric__labels i { font-size: .85rem; }
        .wa-usage-metric__nums { font-weight: 700; color: var(--dash-text); font-size: .78rem; }
        .wa-usage-bar {
            height: 7px; border-radius: 4px;
            background: var(--dash-border);
            overflow: hidden;
        }
        .wa-usage-bar__fill {
            height: 100%; border-radius: 4px;
            transition: width .6s cubic-bezier(.34,1.56,.64,1);
            min-width: 3px;
        }
        .wa-usage-bar__fill--ok     { background: var(--dash-accent); }
        .wa-usage-bar__fill--warn   { background: #f59e0b; }
        .wa-usage-bar__fill--danger { background: #ef4444; }
        .wa-usage-metric__yest {
            font-size: .7rem; color: var(--dash-muted);
            display: flex; align-items: center; gap: 4px;
        }
        .wa-usage-panel__alert {
            display: flex; align-items: center; gap: 8px;
            margin-top: 14px; padding: 9px 13px;
            background: rgba(239,68,68,.07);
            border: 1px solid rgba(239,68,68,.25);
            border-radius: 8px;
            font-size: .78rem; color: #b91c1c;
        }
        .wa-usage-panel__alert i { font-size: .95rem; flex-shrink: 0; }
        .wa-usage-panel__alert a { color: #b91c1c; font-weight: 700; }

        /* ══════════════════════════════════════════════════════
           BANNER MODO MANUTENÇÃO (server2.js)
        ══════════════════════════════════════════════════════ */
        .wa-maintenance-banner {
            display: flex; align-items: center; gap: 14px;
            background: rgba(245,158,11,.08);
            border: 1px solid rgba(245,158,11,.35);
            border-radius: var(--dash-radius);
            padding: 14px 18px; margin-bottom: 18px;
            font-size: .85rem; color: var(--dash-text);
            animation: fadeUp .3s ease both;
        }
        .wa-maintenance-banner i {
            font-size: 1.4rem; color: #f59e0b; flex-shrink: 0;
        }
        .wa-maintenance-banner div {
            display: flex; flex-direction: column; gap: 2px;
        }
        .wa-maintenance-banner strong { font-size: .875rem; color: #92400e; }
        .wa-maintenance-banner span   { font-size: .78rem; color: var(--dash-muted); }
        </style>

        <script>
        const WA_API_BASE   = 'https://api.wixy.com.br';
        const WA_API_KEY    = '<?= NODE_API_KEY ?>';
        const IS_FREE       = <?= $isFreePlan ? 'true' : 'false' ?>;
        const USAGE_HOJE    = <?= json_encode($usageStats['hoje']) ?>;
        const LIMITES_MSGS  = <?= json_encode($limitesMsgs) ?>;
        const PLANO_ATUAL   = '<?= e($planoAtual) ?>';

        // Timers e polls por session_id
        const _timers = {};
        const _polls  = {};

        /* ── Gerar QR Code ─────────────────────────────────── */
        async function gerarQrCode(sid, btn) {
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="ph ph-spinner" style="animation:spin .7s linear infinite;display:inline-block"></i> Gerando…'; }
            _showQrLoading(sid);

            // Para qualquer poll de pairing em andamento antes de iniciar QR
            clearInterval(_pairingPolls[sid]);

            let data = null;
            const MAX_TENTATIVAS = 8; // até ~24s de espera (8 x 3s)
            for (let t = 0; t < MAX_TENTATIVAS; t++) {
                try {
                    const res = await fetch(WA_API_BASE + '/session/start', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'x-api-token': WA_API_KEY },
                        body: JSON.stringify({ session_id: sid }),
                    });
                    data = await res.json();
                    // Se ainda está iniciando, aguarda e tenta novamente
                    if (data.status === 'iniciando') {
                        await new Promise(r => setTimeout(r, 3000));
                        continue;
                    }
                    break; // sucesso ou erro definitivo
                } catch (e) {
                    data = { _error: e.message };
                    break;
                }
            }

            if (data && data.qr_base64) {
                _renderQr(sid, data.qr_base64);
                _startTimer(sid, 60);
                _startPoll(sid);
            } else if (data && data.status === 'conectado') {
                window.location.reload();
            } else {
                _showQrPlaceholder(sid, '<i class="ph ph-warning" style="color:var(--color-danger);font-size:1.8rem;"></i><p style="color:var(--color-danger);font-size:.75rem;">Tente novamente</p>');
            }

            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="ph ph-qr-code"></i> Gerar QR Code'; }
        }

        function _renderQr(sid, base64) {
            const wrap = document.getElementById('msQrImgWrap_' + sid);
            if (!wrap) return;
            wrap.innerHTML = '';
            const img = document.createElement('img');
            img.src = base64;
            img.style.cssText = 'width:170px;height:170px;border-radius:10px;border:3px solid var(--dash-accent);';
            wrap.appendChild(img);
            document.getElementById('msQrLoading_' + sid).style.display  = 'none';
            document.getElementById('msQrPlaceholder_' + sid).style.display = 'none';
            document.getElementById('msQrImage_' + sid).style.display    = 'block';
        }

        function _showQrLoading(sid) {
            const lo = document.getElementById('msQrLoading_' + sid);
            const ph = document.getElementById('msQrPlaceholder_' + sid);
            const im = document.getElementById('msQrImage_' + sid);
            if (lo) lo.style.display = 'flex';
            if (ph) ph.style.display = 'none';
            if (im) im.style.display = 'none';
        }

        function _showQrPlaceholder(sid, html) {
            const lo = document.getElementById('msQrLoading_' + sid);
            const ph = document.getElementById('msQrPlaceholder_' + sid);
            const im = document.getElementById('msQrImage_' + sid);
            if (lo) lo.style.display = 'none';
            if (im) im.style.display = 'none';
            if (ph) { ph.style.display = 'flex'; ph.innerHTML = html; }
        }

        function _startTimer(sid, secs) {
            clearInterval(_timers[sid]);
            let s = secs;
            const el = document.getElementById('msQrCount_' + sid);
            if (el) el.textContent = s;
            _timers[sid] = setInterval(() => {
                s--;
                if (el) el.textContent = s;
                if (s <= 0) {
                    clearInterval(_timers[sid]);
                    _checkStatus(sid, false);
                }
            }, 1000);
        }

        function _startPoll(sid) {
            clearInterval(_polls[sid]);
            _polls[sid] = setInterval(() => _checkStatus(sid, true), 4000);
        }

        async function _checkStatus(sid, silent) {
            try {
                // server2.js: usa /session/realtime (responde da RAM sem consultar banco)
                const res  = await fetch(WA_API_BASE + '/session/realtime/' + sid, {
                    headers: { 'x-api-token': WA_API_KEY }
                });
                const data = await res.json();

                if (data.status === 'conectado') {
                    clearInterval(_timers[sid]);
                    clearInterval(_polls[sid]);
                    // Atualiza banco e recarrega
                    await fetch('dashboard.php?ajax=update_sessao', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ session_id: sid, status: 'conectado', numero: data.numero || '', nome: data.nome || '', foto: data.foto_url || '' })
                    });
                    window.location.reload();
                    return;
                }

                if (data.qr_base64 && data.status === 'aguardando_qr') {
                    const wrapId = 'msQrImgWrap_' + sid;
                    const wrap = document.getElementById(wrapId);
                    if (wrap) {
                        const oldImg = wrap.querySelector('img');
                        if (!oldImg || oldImg.src !== data.qr_base64) {
                            _renderQr(sid, data.qr_base64);
                            _startTimer(sid, 60);
                        }
                    }
                }

                if (!silent && data.status !== 'aguardando_qr') {
                    clearInterval(_polls[sid]);
                    _showQrPlaceholder(sid, '<i class="ph ph-qr-code"></i><p>QR expirado. Gere novamente.</p>');
                }
            } catch (e) {
                if (!silent) _showQrPlaceholder(sid, '<i class="ph ph-warning"></i><p>Erro de conexão.</p>');
            }
        }


        /* ── Seletor de modo de conexão ────────────────────── */
        function setModoConexao(sid, modo) {
            const qrBlock      = document.getElementById('msQrBlock_'      + sid);
            const pairingBlock = document.getElementById('msPairingBlock_' + sid);
            const btnQr        = document.getElementById('msModeQr_'       + sid);
            const btnPairing   = document.getElementById('msModePairing_'  + sid);
            if (modo === 'qr') {
                if (qrBlock)      qrBlock.style.display      = 'block';
                if (pairingBlock) pairingBlock.style.display  = 'none';
                if (btnQr)     { btnQr.classList.add('ms-mode-btn--active');     }
                if (btnPairing){ btnPairing.classList.remove('ms-mode-btn--active'); }
                // Para qualquer poll de pairing em andamento e libera o cliente no servidor
                clearInterval(_pairingPolls[sid]);
                fetch(WA_API_BASE + '/session/disconnect', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'x-api-token': WA_API_KEY },
                    body: JSON.stringify({ session_id: sid }),
                }).catch(() => {});
                // Reseta UI do pairing
                msPairingReset(sid);
            } else {
                if (qrBlock)      qrBlock.style.display      = 'none';
                if (pairingBlock) pairingBlock.style.display  = 'block';
                if (btnPairing){ btnPairing.classList.add('ms-mode-btn--active');  }
                if (btnQr)     { btnQr.classList.remove('ms-mode-btn--active');    }
                // Para qualquer poll de QR em andamento e desconecta a sessão de QR ativa
                clearInterval(_timers[sid]);
                clearInterval(_polls[sid]);
                // Se havia QR ativo, envia disconnect para liberar o Chromium
                // (o pairing-code irá destruir e recriar o cliente no servidor)
                fetch(WA_API_BASE + '/session/disconnect', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'x-api-token': WA_API_KEY },
                    body: JSON.stringify({ session_id: sid }),
                }).catch(() => {});
                // Reseta UI do QR para estado inicial
                _showQrPlaceholder(sid, '<i class="ph ph-qr-code"></i><p>Clique em <strong>Gerar QR Code</strong></p>');
            }
        }

        /* ── Pairing Code — timers e polls ─────────────────── */
        const _pairingPolls = {};
        const MAX_PAIRING_TENTATIVAS = 3;

        /* ── País telefônico: inicializa selects de país ─────── */
        const MS_COUNTRIES = [
            { code: '55',  flag: '🇧🇷', name: 'Brasil',           fmt: 'BR'  },
            { code: '1',   flag: '🇺🇸', name: 'EUA / Canadá',     fmt: 'US'  },
            { code: '54',  flag: '🇦🇷', name: 'Argentina',        fmt: 'AR'  },
            { code: '56',  flag: '🇨🇱', name: 'Chile',            fmt: 'CL'  },
            { code: '57',  flag: '🇨🇴', name: 'Colômbia',         fmt: 'CO'  },
            { code: '51',  flag: '🇵🇪', name: 'Peru',             fmt: 'PE'  },
            { code: '58',  flag: '🇻🇪', name: 'Venezuela',        fmt: 'VE'  },
            { code: '595', flag: '🇵🇾', name: 'Paraguai',         fmt: 'PY'  },
            { code: '598', flag: '🇺🇾', name: 'Uruguai',          fmt: 'UY'  },
            { code: '591', flag: '🇧🇴', name: 'Bolívia',          fmt: 'BO'  },
            { code: '593', flag: '🇪🇨', name: 'Equador',          fmt: 'EC'  },
            { code: '502', flag: '🇬🇹', name: 'Guatemala',        fmt: 'GT'  },
            { code: '503', flag: '🇸🇻', name: 'El Salvador',      fmt: 'SV'  },
            { code: '504', flag: '🇭🇳', name: 'Honduras',         fmt: 'HN'  },
            { code: '505', flag: '🇳🇮', name: 'Nicarágua',        fmt: 'NI'  },
            { code: '506', flag: '🇨🇷', name: 'Costa Rica',       fmt: 'CR'  },
            { code: '507', flag: '🇵🇦', name: 'Panamá',           fmt: 'PA'  },
            { code: '52',  flag: '🇲🇽', name: 'México',           fmt: 'MX'  },
            { code: '34',  flag: '🇪🇸', name: 'Espanha',          fmt: 'ES'  },
            { code: '351', flag: '🇵🇹', name: 'Portugal',         fmt: 'PT'  },
            { code: '44',  flag: '🇬🇧', name: 'Reino Unido',      fmt: 'GB'  },
            { code: '33',  flag: '🇫🇷', name: 'França',           fmt: 'FR'  },
            { code: '49',  flag: '🇩🇪', name: 'Alemanha',         fmt: 'DE'  },
            { code: '39',  flag: '🇮🇹', name: 'Itália',           fmt: 'IT'  },
            { code: '351', flag: '🇵🇹', name: 'Portugal',         fmt: 'PT'  },
            { code: '7',   flag: '🇷🇺', name: 'Rússia',           fmt: 'RU'  },
            { code: '86',  flag: '🇨🇳', name: 'China',            fmt: 'CN'  },
            { code: '81',  flag: '🇯🇵', name: 'Japão',            fmt: 'JP'  },
            { code: '82',  flag: '🇰🇷', name: 'Coreia do Sul',    fmt: 'KR'  },
            { code: '91',  flag: '🇮🇳', name: 'Índia',            fmt: 'IN'  },
            { code: '971', flag: '🇦🇪', name: 'Emirados Árabes',  fmt: 'AE'  },
            { code: '966', flag: '🇸🇦', name: 'Arábia Saudita',   fmt: 'SA'  },
            { code: '972', flag: '🇮🇱', name: 'Israel',           fmt: 'IL'  },
            { code: '27',  flag: '🇿🇦', name: 'África do Sul',    fmt: 'ZA'  },
            { code: '234', flag: '🇳🇬', name: 'Nigéria',          fmt: 'NG'  },
            { code: '20',  flag: '🇪🇬', name: 'Egito',            fmt: 'EG'  },
            { code: '61',  flag: '🇦🇺', name: 'Austrália',        fmt: 'AU'  },
            { code: '64',  flag: '🇳🇿', name: 'Nova Zelândia',    fmt: 'NZ'  },
        ];

        // Remove duplicatas por code+name
        const _seenCountry = new Set();
        const MS_COUNTRIES_UNIQ = MS_COUNTRIES.filter(c => {
            const k = c.code + '_' + c.name;
            if (_seenCountry.has(k)) return false;
            _seenCountry.add(k); return true;
        });

        function msFlagUrl(fmt) {
            return 'https://flagcdn.com/20x15/' + fmt.toLowerCase() + '.png';
        }

        function msInitCountrySelect(sid) {
            const sel = document.getElementById('msPairingCountry_' + sid);
            if (!sel) return;

            // Evita dupla inicialização do custom dropdown
            const existingBtn = document.getElementById('msPairingCountryBtn_' + sid);
            if (existingBtn) { existingBtn.remove(); }
            const existingDrop = document.getElementById('msPairingCountryDrop_' + sid);
            if (existingDrop) { existingDrop.remove(); }

            // Valor padrão
            let selectedCode = '55';
            let selectedFmt  = 'BR';

            // Wrapper relativo para posicionar o dropdown
            const phoneRow = sel.parentElement;

            // Botão trigger
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.id   = 'msPairingCountryBtn_' + sid;
            btn.className = 'ms-country-btn';
            btn.dataset.dropId = 'msPairingCountryDrop_' + sid;
            btn.innerHTML =
                '<img class="ms-flag" src="' + msFlagUrl(selectedFmt) + '" alt="' + selectedFmt + '">' +
                '<span class="ms-ddi">+' + selectedCode + '</span>' +
                '<span class="ms-caret">▼</span>';
            phoneRow.insertBefore(btn, sel.nextSibling);

            // Dropdown — anexado ao body para escapar de qualquer overflow:hidden
            const drop = document.createElement('div');
            drop.id        = 'msPairingCountryDrop_' + sid;
            drop.className = 'ms-country-dropdown';
            document.body.appendChild(drop);

            MS_COUNTRIES_UNIQ.forEach(function(c) {
                const item = document.createElement('div');
                item.className = 'ms-country-option' + (c.code === '55' && c.fmt === 'BR' ? ' selected' : '');
                item.dataset.code = c.code;
                item.dataset.fmt  = c.fmt;
                item.innerHTML =
                    '<img class="ms-flag" src="' + msFlagUrl(c.fmt) + '" alt="' + c.fmt + '">' +
                    '<span class="ms-opt-name">' + c.name + '</span>' +
                    '<span class="ms-opt-ddi">+' + c.code + '</span>';
                item.addEventListener('click', function() {
                    selectedCode = c.code;
                    selectedFmt  = c.fmt;
                    // Atualiza botão trigger
                    btn.querySelector('img.ms-flag').src = msFlagUrl(c.fmt);
                    btn.querySelector('img.ms-flag').alt = c.fmt;
                    btn.querySelector('.ms-ddi').textContent = '+' + c.code;
                    // Marca selected
                    drop.querySelectorAll('.ms-country-option').forEach(function(el) { el.classList.remove('selected'); });
                    item.classList.add('selected');
                    // Sincroniza o select oculto (para msFmtPhone/msGetFullPhone)
                    sel.value = c.code;
                    // Fecha dropdown
                    drop.classList.remove('open');
                    btn.classList.remove('open');
                    // Reformata telefone
                    msFmtPhone(sid);
                });
                drop.appendChild(item);
            });

            function _positionDrop() {
                const rect = btn.getBoundingClientRect();
                drop.style.top  = (rect.bottom + 4) + 'px';
                drop.style.left = rect.left + 'px';
                drop.style.minWidth = Math.max(220, rect.width) + 'px';
            }

            // Toggle dropdown
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const isOpen = drop.classList.contains('open');
                // Fecha todos os outros dropdowns abertos
                document.querySelectorAll('.ms-country-dropdown.open').forEach(function(d) {
                    d.classList.remove('open');
                    const b = document.querySelector('[data-drop-id="' + d.id + '"]');
                    if (b) b.classList.remove('open');
                });
                if (!isOpen) {
                    _positionDrop();
                    drop.classList.add('open');
                    btn.classList.add('open');
                }
            });

            // Inicializa o select oculto com o valor padrão
            if (!sel.dataset.init) {
                sel.dataset.init = '1';
                const optDefault = document.createElement('option');
                optDefault.value = '55'; optDefault.textContent = '+55 BR'; optDefault.selected = true;
                sel.appendChild(optDefault);
                MS_COUNTRIES_UNIQ.forEach(function(c) {
                    if (c.code === '55' && c.fmt === 'BR') return;
                    const opt = document.createElement('option');
                    opt.value = c.code; opt.textContent = '+' + c.code + ' ' + c.fmt;
                    sel.appendChild(opt);
                });
            }
            sel.value = '55';
        }

        // Fecha dropdowns ao clicar fora
        document.addEventListener('click', function() {
            document.querySelectorAll('.ms-country-dropdown.open').forEach(function(d) {
                d.classList.remove('open');
            });
            document.querySelectorAll('.ms-country-btn.open').forEach(function(b) {
                b.classList.remove('open');
            });
        });

        // Reposiciona dropdowns abertos ao fazer scroll ou resize
        function _reposicionarDropdowns() {
            document.querySelectorAll('.ms-country-btn.open').forEach(function(b) {
                const dropId = b.dataset.dropId;
                if (!dropId) return;
                const d = document.getElementById(dropId);
                if (!d) return;
                const rect = b.getBoundingClientRect();
                d.style.top  = (rect.bottom + 4) + 'px';
                d.style.left = rect.left + 'px';
            });
        }
        window.addEventListener('scroll', _reposicionarDropdowns, true);
        window.addEventListener('resize', _reposicionarDropdowns);

        // Formata o campo de telefone conforme o país selecionado
        function msFmtPhone(sid) {
            const sel     = document.getElementById('msPairingCountry_' + sid);
            const input   = document.getElementById('msPairingPhone_' + sid);
            if (!sel || !input) return;
            const code    = sel.value;
            let   raw     = input.value.replace(/\D/g, '');

            if (code === '55') {
                // Brasil: (DD) NNNNN-NNNN ou (DD) NNNN-NNNN
                if (raw.length > 11) raw = raw.slice(0, 11);
                let fmt = '';
                if (raw.length > 0)  fmt = '(' + raw.slice(0, 2);
                if (raw.length > 2)  fmt += ') ' + raw.slice(2, raw.length > 10 ? 7 : 6);
                if (raw.length > (raw.length > 10 ? 7 : 6)) fmt += '-' + raw.slice(raw.length > 10 ? 7 : 6);
                input.value = fmt;
                input.placeholder = '(11) 99999-8888';
            } else {
                // Outros países: sem formatação especial, só dígitos
                if (raw.length > 15) raw = raw.slice(0, 15);
                input.value = raw;
                input.placeholder = 'Número local';
            }
        }

        // Retorna o número completo com DDI para enviar à API
        function msGetFullPhone(sid) {
            const sel   = document.getElementById('msPairingCountry_' + sid);
            const input = document.getElementById('msPairingPhone_' + sid);
            if (!sel || !input) return '';
            const ddi = sel.value;
            const num = input.value.replace(/\D/g, '');
            return ddi + num;
        }

        // Inicializa todos os selects de país já existentes no DOM
        (function() {
            document.querySelectorAll('[id^="msPairingCountry_"]').forEach(function(el) {
                const sid = el.id.replace('msPairingCountry_', '');
                msInitCountrySelect(sid);
            });
        })();

        /* ── Gerar Pairing Code ─────────────────────────────── */
        async function gerarPairingCode(sid, btn) {
            // Para qualquer poll de QR em andamento antes de iniciar pairing
            clearInterval(_timers[sid]);
            clearInterval(_polls[sid]);

            msInitCountrySelect(sid);
            const phone = msGetFullPhone(sid);
            const localNum = document.getElementById('msPairingPhone_' + sid);
            const localRaw = localNum ? localNum.value.replace(/\D/g, '') : '';
            if (!localRaw || localRaw.length < 8) {
                alert('Digite o número completo com DDD.');
                return;
            }
            if (!phone || phone.length < 10) {
                alert('Número inválido. Verifique o código do país e o número.');
                return;
            }

            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="ph ph-spinner" style="animation:spin .7s linear infinite;display:inline-block"></i> Gerando…'; }

            try {
                const res  = await fetch(WA_API_BASE + '/session/pairing-code', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'x-api-token': WA_API_KEY },
                    body: JSON.stringify({ session_id: sid, phone }),
                });
                const data = await res.json();

                if (!res.ok || data.error) {
                    alert('Erro: ' + (data.error || 'Não foi possível gerar o código.'));
                    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="ph ph-device-mobile"></i> Gerar Código'; }
                    return;
                }

                if (data.status === 'conectado') {
                    window.location.reload();
                    return;
                }

                if (data.pairing_code) {
                    _exibirPairingCode(sid, data.pairing_code, data.pairing_tentativas || 1, data.max_pairing_tentativas || MAX_PAIRING_TENTATIVAS);
                    _startPairingPoll(sid);
                }

            } catch (e) {
                alert('Erro de conexão: ' + e.message);
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="ph ph-device-mobile"></i> Gerar Código'; }
            }
        }

        function _exibirPairingCode(sid, code, tentativa, maxTentativas) {
            const display   = document.getElementById('msPairingCodeDisplay_' + sid);
            const codeEl    = document.getElementById('msPairingCode_'        + sid);
            const inputWrap = document.querySelector('#msPairingBlock_' + sid + ' .ms-pairing-input-wrap');
            const btn       = document.getElementById('msPairingBtn_'   + sid);
            if (codeEl)    codeEl.textContent    = code;
            if (display)   display.style.display = 'flex';
            if (inputWrap) inputWrap.style.display = 'none';
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="ph ph-check"></i> Código gerado'; }

            // Atualiza indicador de tentativas (bolinhas)
            // Dots até a tentativa atual = active; dots após = inativas
            for (var i = 1; i <= 3; i++) {
                var dot = document.getElementById('msPairingDot' + i + '_' + sid);
                if (dot) {
                    dot.className = 'ms-pairing-dot';
                    if (i <= tentativa) dot.classList.add('ms-pairing-dot--active');
                }
            }

            // Anima o código se estiver atualizando (tentativa > 1)
            if (tentativa > 1 && codeEl) {
                codeEl.style.animation = 'none';
                codeEl.offsetHeight;
                codeEl.style.animation = 'pairingCodePulse .5s ease both';
            }
        }

        function _updatePairingAttempt(sid, tentativa, maxTentativas) {
            _exibirPairingCode(sid,
                document.getElementById('msPairingCode_' + sid)?.textContent || '----',
                tentativa,
                maxTentativas
            );
        }

        function _startPairingPoll(sid) {
            clearInterval(_pairingPolls[sid]);
            let _lastTentativa = 1; // rastreia a última tentativa conhecida localmente
            _pairingPolls[sid] = setInterval(async () => {
                try {
                    // server2.js: /session/realtime = status da RAM sem tocar no banco
                    const res  = await fetch(WA_API_BASE + '/session/realtime/' + sid, {
                        headers: { 'x-api-token': WA_API_KEY }
                    });
                    const data = await res.json();

                    if (data.status === 'conectado') {
                        clearInterval(_pairingPolls[sid]);
                        await fetch('dashboard.php?ajax=update_sessao', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ session_id: sid, status: 'conectado', numero: data.numero || '', nome: data.nome || '', foto: data.foto_url || '' })
                        });
                        window.location.reload();
                        return;
                    }

                    // Busca o estado atual do pairing (código + tentativas)
                    const codeRes  = await fetch(WA_API_BASE + '/session/pairing-code/' + sid, {
                        headers: { 'x-api-token': WA_API_KEY }
                    });
                    const codeData = await codeRes.json();

                    if (codeData.pairing_code) {
                        const tentativa    = codeData.pairing_tentativas || 1;
                        const maxTentativas = codeData.max_pairing_tentativas || MAX_PAIRING_TENTATIVAS;
                        const codeEl       = document.getElementById('msPairingCode_' + sid);
                        const currentCode  = codeEl ? codeEl.textContent.trim() : '';

                        // Sempre atualiza bolinhas se a tentativa avançou
                        if (tentativa !== _lastTentativa || codeData.pairing_code !== currentCode) {
                            _lastTentativa = tentativa;
                            _exibirPairingCode(sid, codeData.pairing_code, tentativa, maxTentativas);
                        }

                        // Para o poll e mostra "tentar novamente" ao atingir o limite
                        if (tentativa >= maxTentativas) {
                            clearInterval(_pairingPolls[sid]);
                            const statusEl = document.getElementById('msPairingStatus_' + sid);
                            if (statusEl) {
                                statusEl.innerHTML = '<span style="color:var(--color-danger);font-size:.75rem;"><i class="ph ph-warning-circle"></i> Limite de tentativas atingido. <button class="ms-pairing-retry-btn" onclick="msPairingReset(\'' + sid + '\')">Tentar novamente</button></span>';
                            }
                        }
                    }

                } catch { /* ignora */ }
            }, 3000);
        }

        // Reseta o pairing para tentar novamente
        function msPairingReset(sid) {
            clearInterval(_pairingPolls[sid]);
            const display   = document.getElementById('msPairingCodeDisplay_' + sid);
            const inputWrap = document.querySelector('#msPairingBlock_' + sid + ' .ms-pairing-input-wrap');
            const btn       = document.getElementById('msPairingBtn_'   + sid);
            if (display)   display.style.display = 'none';
            if (inputWrap) inputWrap.style.display = '';
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="ph ph-device-mobile"></i> Gerar Código'; }
            msInitCountrySelect(sid);
        }

        /* ── Verificar status manual ───────────────────────── */
        async function verificarStatusWA(sid, silent) {
            try {
                const res  = await fetch(WA_API_BASE + '/session/status/' + sid, {
                    headers: { 'x-api-token': WA_API_KEY }
                });
                const data = await res.json();
                if (data.status === 'conectado') {
                    await fetch('dashboard.php?ajax=update_sessao', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ session_id: sid, status: 'conectado', numero: data.numero || '', nome: data.nome || '', foto: data.foto_url || '' })
                    });
                    window.location.reload();
                }
            } catch(e) { if (!silent) alert('Erro: ' + e.message); }
        }

        /* ── Desconectar ───────────────────────────────────── */
        async function desconectarWA(sid, btn) {
            if (!confirm('Desconectar esta sessão do WhatsApp?')) return;
            if (btn) btn.disabled = true;
            try {
                await fetch(WA_API_BASE + '/session/disconnect', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'x-api-token': WA_API_KEY },
                    body: JSON.stringify({ session_id: sid })
                });
                await fetch('dashboard.php?ajax=update_sessao', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ session_id: sid, status: 'desconectado', numero: '', nome: '', foto: '' })
                });
                window.location.reload();
            } catch(e) {
                alert('Erro: ' + e.message);
                if (btn) btn.disabled = false;
            }
        }

        /* ── Adicionar sessão ──────────────────────────────── */
        async function adicionarSessao() {
            if (IS_FREE) { alert('Sessão múltipla está disponível apenas no plano pago.'); return; }
            const btn = document.getElementById('btnAddSessao');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="ph ph-spinner" style="animation:spin .7s linear infinite;display:inline-block"></i> Criando…'; }
            try {
                const res  = await fetch('dashboard.php?ajax=add_sessao');
                const data = await res.json();
                if (data.ok) {
                    window.location.reload();
                } else {
                    alert(data.erro || 'Não foi possível adicionar a sessão.');
                    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="ph ph-plus"></i> Nova Sessão'; }
                }
            } catch(e) {
                alert('Erro: ' + e.message);
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="ph ph-plus"></i> Nova Sessão'; }
            }
        }

        /* ── Remover sessão ────────────────────────────────── */
        async function removerSessao(sid) {
            if (!confirm('Remover esta sessão? O WhatsApp será desconectado.')) return;
            try {
                // Desconecta no servidor
                await fetch(WA_API_BASE + '/session/disconnect', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'x-api-token': WA_API_KEY },
                    body: JSON.stringify({ session_id: sid })
                }).catch(() => {});
                const res  = await fetch('dashboard.php?ajax=del_sessao&session_id=' + encodeURIComponent(sid));
                const data = await res.json();
                if (data.ok) {
                    const card = document.querySelector('[data-session-id="' + sid + '"]');
                    if (card) {
                        card.style.transition = 'opacity .3s, transform .3s';
                        card.style.opacity = '0'; card.style.transform = 'scale(.9)';
                        setTimeout(() => window.location.reload(), 280);
                    } else window.location.reload();
                } else alert(data.erro || 'Não foi possível remover.');
            } catch(e) { alert('Erro: ' + e.message); }
        }

        /* ── Renomear sessão ───────────────────────────────── */
        async function renomearSessao(sid) {
            const apelidoEl = document.getElementById('msApelido_' + sid);
            const atual = apelidoEl ? apelidoEl.textContent.trim() : '';
            const novo = prompt('Novo nome para esta sessão:', atual);
            if (!novo || novo === atual) return;
            try {
                const res  = await fetch('dashboard.php?ajax=rename_sessao', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ session_id: sid, apelido: novo })
                });
                const data = await res.json();
                if (data.ok && apelidoEl) {
                    apelidoEl.textContent = novo;
                    // Micro-animação
                    apelidoEl.style.color = 'var(--dash-accent-dk)';
                    setTimeout(() => apelidoEl.style.color = '', 800);
                }
            } catch(e) { alert('Erro ao renomear: ' + e.message); }
        }

        /* ── Refresh estatísticas de uso (server2.js /usage/:id) ─── */
        async function refreshUsageStats() {
            const icon = document.getElementById('usageRefreshIcon');
            if (icon) icon.style.animation = 'spin .7s linear infinite';
            try {
                const res  = await fetch('dashboard.php?ajax=usage_stats');
                const data = await res.json();
                if (data && data.hoje) {
                    const limIA = data.limites?.ia ?? LIMITES_MSGS.ia;
                    const limAR = data.limites?.ar ?? LIMITES_MSGS.ar;
                    _renderUsageBars(data.hoje.ia, data.hoje.ar, limIA, limAR, data.ontem);
                    // Alerta de limite
                    const pIA  = limIA > 0 ? Math.round(data.hoje.ia / limIA * 100) : 0;
                    const pAR  = limAR > 0 ? Math.round(data.hoje.ar / limAR * 100) : 0;
                    const alertEl = document.querySelector('.wa-usage-panel__alert');
                    if (alertEl) alertEl.style.display = (pIA >= 90 || pAR >= 90) ? 'flex' : 'none';
                }
            } catch (e) { /* silencioso */ }
            if (icon) setTimeout(() => { icon.style.animation = ''; }, 600);
        }

        function _renderUsageBars(iaHoje, arHoje, limIA, limAR, ontem) {
            const body = document.getElementById('waUsagePanelBody');
            if (!body) return;
            const pIA  = limIA > 0 ? Math.min(100, Math.round(iaHoje / limIA * 100)) : 0;
            const pAR  = limAR > 0 ? Math.min(100, Math.round(arHoje / limAR * 100)) : 0;
            const cls  = (p) => p >= 90 ? 'danger' : (p >= 70 ? 'warn' : 'ok');
            const fmt  = (n) => (+n).toLocaleString('pt-BR');
            const yestIA = ontem?.ia > 0 ? `<span class="wa-usage-metric__yest">Ontem: ${fmt(ontem.ia)}</span>` : '';
            const yestAR = ontem?.ar > 0 ? `<span class="wa-usage-metric__yest">Ontem: ${fmt(ontem.ar)}</span>` : '';
            body.innerHTML = `
                <div class="wa-usage-metric">
                    <div class="wa-usage-metric__labels">
                        <span><i class="ph ph-robot"></i> Mensagens IA</span>
                        <span class="wa-usage-metric__nums">${fmt(iaHoje)} / ${fmt(limIA)}</span>
                    </div>
                    <div class="wa-usage-bar"><div class="wa-usage-bar__fill wa-usage-bar__fill--${cls(pIA)}" style="width:${pIA}%"></div></div>
                    ${yestIA}
                </div>
                <div class="wa-usage-metric">
                    <div class="wa-usage-metric__labels">
                        <span><i class="ph ph-lightning"></i> Auto Respostas</span>
                        <span class="wa-usage-metric__nums">${fmt(arHoje)} / ${fmt(limAR)}</span>
                    </div>
                    <div class="wa-usage-bar"><div class="wa-usage-bar__fill wa-usage-bar__fill--${cls(pAR)}" style="width:${pAR}%"></div></div>
                    ${yestAR}
                </div>`;
        }

        /* ── Verificar modo manutenção ao carregar (server2.js GET /) ─── */
        (async function checkServerHealth() {
            try {
                const res  = await fetch('dashboard.php?ajax=server_health');
                const data = await res.json();
                const banner = document.getElementById('waMaintenanceBanner');
                if (banner) banner.style.display = data.modoManutencao ? 'flex' : 'none';
                if (data.modoManutencao) console.warn('[Wixy] Servidor em manutenção.');
            } catch { /* silencioso */ }
        })();

        /* ── Auto-refresh do painel de uso a cada 60s ─────────────── */
        setInterval(refreshUsageStats, 60000);
        </script>

        <?php endif; ?>

        <!-- ════════════════════════════════════════════════════════════════════
             ABA: HUMANIZAÇÃO
        ═══════════════════════════════════════════════════════════════════════ -->
        <?php if ($aba === 'humanizacao'): ?>
        <div class="dash-section">

            <div class="dash-section__header">
                <h1 class="dash-section__title">
                    <i class="ph ph-smiley-wink"></i>
                    Humanização
                </h1>
                <p class="dash-section__sub">Configure como a IA imita o comportamento humano nas respostas.</p>
            </div>

            <form method="POST" class="dash-form">
                <input type="hidden" name="acao" value="salvar_humanizacao">

                <!-- Delay humanizado -->
                <div class="dash-card">
                    <div class="dash-card__header">
                        <i class="ph ph-timer"></i>
                        <h2>Delay Humanizado</h2>
                        <?php if (!$limTudo): ?><span class="dash-free-badge"><i class="ph ph-lock-simple"></i> Limitado</span><?php endif; ?>
                    </div>
                    <p class="dash-card__desc">Simula o tempo que uma pessoa demora para digitar antes de responder.</p>
                    <?php if (!$limTudo): ?>
                    <div class="dash-free-notice">
                        <i class="ph ph-info"></i>
                        <span>Seu plano permite delay mínimo até <strong><?= $freeLimits['delay_min'] ?>s</strong> e máximo até <strong><?= $freeLimits['delay_max'] ?>s</strong>.</span>
                    </div>
                    <?php endif; ?>
                    <div class="dash-range-group">
                        <div class="dash-range-item">
                            <label>Delay Mínimo: <strong id="lblDelayMin"><?= e(min((int)$cfg['delay_min'], $freeLimits['delay_min'])) ?>s</strong>
                                <?php if (!$limTudo): ?><small style="display:inline;color:var(--dash-muted);"> / <?= $globalLimits['delay_min'] ?>s máx.</small><?php endif; ?>
                            </label>
                            <input type="range" name="delay_min" min="0" max="<?= $globalLimits['delay_min'] ?>" step="1"
                                   value="<?= e(min((int)$cfg['delay_min'], $freeLimits['delay_min'])) ?>"
                                   data-plan-max="<?= $freeLimits['delay_min'] ?>"
                                   <?= !$limTudo ? 'data-free-max="'.$freeLimits['delay_min'].'"' : '' ?>
                                   oninput="handleFreeRange(this, 'lblDelayMin', function(v){ return v + 's'; })">
                        </div>
                        <div class="dash-range-item">
                            <label>Delay Máximo: <strong id="lblDelayMax"><?= e(min((int)$cfg['delay_max'], $freeLimits['delay_max'])) ?>s</strong>
                                <?php if (!$limTudo): ?><small style="display:inline;color:var(--dash-muted);"> / <?= $globalLimits['delay_max'] ?>s máx.</small><?php endif; ?>
                            </label>
                            <input type="range" name="delay_max" min="0" max="<?= $globalLimits['delay_max'] ?>" step="1"
                                   value="<?= e(min((int)$cfg['delay_max'], $freeLimits['delay_max'])) ?>"
                                   data-plan-max="<?= $freeLimits['delay_max'] ?>"
                                   <?= !$limTudo ? 'data-free-max="'.$freeLimits['delay_max'].'"' : '' ?>
                                   oninput="handleFreeRange(this, 'lblDelayMax', function(v){ return v + 's'; })">
                        </div>
                    </div>
                </div>

                <!-- Contexto -->
                <div class="dash-card">
                    <div class="dash-card__header">
                        <i class="ph ph-brain"></i>
                        <h2>Memória de Contexto</h2>
                        <?php if (!$limTudo): ?><span class="dash-free-badge"><i class="ph ph-lock-simple"></i> Limitado</span><?php endif; ?>
                    </div>
                    <?php if (!$limTudo): ?>
                    <div class="dash-free-notice">
                        <i class="ph ph-info"></i>
                        <span>Seu plano permite até <strong><?= $freeLimits['contexto_msgs'] ?> mensagens</strong> de contexto.</span>
                    </div>
                    <?php endif; ?>
                    <div class="dash-range-item">
                        <label>Mensagens no contexto: <strong id="lblCtx"><?= e(min((int)$cfg['contexto_msgs'], $freeLimits['contexto_msgs'])) ?></strong>
                            <?php if (!$limTudo): ?><small style="display:inline;color:var(--dash-muted);"> / <?= $globalLimits['contexto_msgs'] ?> máx.</small><?php endif; ?>
                        </label>
                        <input type="range" name="contexto_msgs" min="1" max="<?= $globalLimits['contexto_msgs'] ?>" step="1"
                               value="<?= e(min((int)$cfg['contexto_msgs'], $freeLimits['contexto_msgs'])) ?>"
                               data-plan-max="<?= $freeLimits['contexto_msgs'] ?>"
                               <?= !$limTudo ? 'data-free-max="'.$freeLimits['contexto_msgs'].'"' : '' ?>
                               oninput="handleFreeRange(this, 'lblCtx', function(v){ return v; })">
                        <small>Quantas mensagens anteriores a IA "lembra" por conversa.</small>
                    </div>
                </div>

                <!-- Fallback -->
                <div class="dash-card">
                    <div class="dash-card__header">
                        <i class="ph ph-warning-circle"></i>
                        <h2>Mensagem de Fallback</h2>
                    </div>
                    <p class="dash-card__desc">Enviada quando ocorre erro na API de IA.</p>
                    <div class="dash-inline-group">
                        <label class="dash-toggle dash-toggle--inline">
                            <input type="checkbox" name="fallback_ativo" <?= $cfg['fallback_ativo'] === '1' ? 'checked' : '' ?>>
                            <span class="dash-toggle__slider"></span>
                            <span class="dash-toggle__label">Ativar fallback</span>
                        </label>
                        <input type="text" name="fallback_msg" class="dash-input"
                               value="<?= e($cfg['fallback_msg']) ?>"
                               placeholder="Mensagem de fallback…">
                    </div>
                </div>

                <!-- Humanização básica -->
                <div class="dash-card">
                    <div class="dash-card__header">
                        <i class="ph ph-magic-wand"></i>
                        <h2>Humanização Básica</h2>
                        <?php if (!empty($freeBlockedToggles)): ?><span class="dash-free-badge"><i class="ph ph-lock-simple"></i> Parcialmente limitado</span><?php endif; ?>
                    </div>
                    <div class="dash-toggles-grid">
                        <?php
                        $togBasico = [
                            ['humanizar_erros',       'Conector de ação',         'Prefixa a resposta com frases como "Deixa eu verificar aqui..." ou "Só um momento,"'],
                            ['humanizar_abrev',       'Abreviações informais',    'Substitui palavras por "vc", "tb", "pq", "pra", "tá", etc.'],
                            ['humanizar_reticencias', 'Reticências',              'Substitui vírgulas por ... às vezes'],
                            ['humanizar_emoji',       'Limpar emojis duplicados', 'Remove emojis repetidos na mesma mensagem'],
                            ['humanizar_minusc',      'Minúscula no início',      'Começa frases com letra minúscula às vezes'],
                            ['humanizar_pontuacao',   'Sem ponto final',          'Remove o ponto final em frases curtas'],
                        ];
                        foreach ($togBasico as [$key, $label, $desc]):
                            $isBlocked = in_array($key, $freeBlockedToggles);
                        ?>
                        <?php if ($isBlocked): ?>
                        <div class="dash-toggle dash-toggle--locked" title="Disponível apenas no plano pago">
                            <span class="dash-toggle__slider dash-toggle__slider--locked"></span>
                            <span class="dash-toggle__info">
                                <span class="dash-toggle__label">
                                    <?= e($label) ?>
                                    <span class="dash-lock-icon"><i class="ph ph-lock-simple"></i> Premium</span>
                                </span>
                                <span class="dash-toggle__desc"><?= e($desc) ?></span>
                            </span>
                            <input type="hidden" name="<?= $key ?>" value="0">
                        </div>
                        <?php else: ?>
                        <label class="dash-toggle">
                            <input type="checkbox" name="<?= $key ?>" <?= $cfg[$key] === '1' ? 'checked' : '' ?>>
                            <span class="dash-toggle__slider"></span>
                            <span class="dash-toggle__info">
                                <span class="dash-toggle__label"><?= e($label) ?></span>
                                <span class="dash-toggle__desc"><?= e($desc) ?></span>
                            </span>
                        </label>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Humanização avançada -->
                <div class="dash-card">
                    <div class="dash-card__header">
                        <i class="ph ph-sparkle"></i>
                        <h2>Humanização Avançada</h2>
                        <?php if (!empty($freeBlockedToggles)): ?><span class="dash-free-badge"><i class="ph ph-lock-simple"></i> Parcialmente limitado</span><?php endif; ?>
                    </div>
                    <div class="dash-toggles-grid">
                        <?php
                        $togAvancado = [
                            ['humanizar_girias',         'Confirmações empáticas',  'Prefixa com "Perfeito.", "Entendi.", "Combinado.", "Pode deixar.", etc.'],
                            ['humanizar_hesitacao',      'Hesitações / conectores', 'Prefixa com "Deixa eu verificar aqui...", "Então,", "Compreendo.", etc.'],
                            ['humanizar_risada',         'Converter risadas',       'Transforma haha/lol em rsrs, haha ou 😊'],
                            ['humanizar_fragmentar',     'Fragmentar resposta',     'Divide a resposta em 2 mensagens separadas com pausa entre elas'],
                            ['humanizar_delay_extra',    'Delay extra de leitura',  'Simula que leu mas demorou para responder'],
                            ['humanizar_emoji_reacao',   'Emoji de reação',         'Adiciona 👍 🙏 ✨ 🤝 📌 no final da mensagem ocasionalmente'],
                            ['humanizar_repetir_palavra','Ênfase empática',         'Prefixa com "certamente,", "com certeza," ou "perfeitamente,"'],
                            ['humanizar_ne_final',       'Fechamento empático',     'Adiciona "Tudo bem?" ou "Combinado?" no final às vezes'],
                        ];
                        foreach ($togAvancado as [$key, $label, $desc]):
                            $isBlocked = in_array($key, $freeBlockedToggles);
                        ?>
                        <?php if ($isBlocked): ?>
                        <div class="dash-toggle dash-toggle--locked" title="Disponível apenas no plano pago">
                            <span class="dash-toggle__slider dash-toggle__slider--locked"></span>
                            <span class="dash-toggle__info">
                                <span class="dash-toggle__label">
                                    <?= e($label) ?>
                                    <span class="dash-lock-icon"><i class="ph ph-lock-simple"></i> Premium</span>
                                </span>
                                <span class="dash-toggle__desc"><?= e($desc) ?></span>
                            </span>
                            <input type="hidden" name="<?= $key ?>" value="0">
                        </div>
                        <?php else: ?>
                        <label class="dash-toggle">
                            <input type="checkbox" name="<?= $key ?>" <?= $cfg[$key] === '1' ? 'checked' : '' ?>>
                            <span class="dash-toggle__slider"></span>
                            <span class="dash-toggle__info">
                                <span class="dash-toggle__label"><?= e($label) ?></span>
                                <span class="dash-toggle__desc"><?= e($desc) ?></span>
                            </span>
                        </label>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Probabilidades -->
                <div class="dash-card">
                    <div class="dash-card__header">
                        <i class="ph ph-percent"></i>
                        <h2>Probabilidades (%)</h2>
                    </div>
                    <p class="dash-card__desc">Controla a frequência com que cada efeito de humanização é aplicado.</p>
                    <div class="dash-probs-grid">
                        <?php
                        $probs = [
                            ['prob_minusc',       'Minúscula'],
                            ['prob_pontuacao',    'Sem ponto final'],
                            ['prob_abrev',        'Abreviações'],
                            ['prob_reticencias',  'Reticências'],
                            ['prob_erros',        'Erros de digitação'],
                            ['prob_girias',       'Gírias'],
                            ['prob_hesitacao',    'Hesitações'],
                            ['prob_risada',       'Converter risadas'],
                            ['prob_caps',         'CAPS de ênfase'],
                            ['prob_emoji_reacao', 'Emoji de reação'],
                            ['prob_ne_final',     'Né?/Sabe?'],
                        ];
                        foreach ($probs as [$key, $label]):
                            $val = (int)($cfg[$key] ?? 20);
                        ?>
                        <div class="dash-prob-item">
                            <div class="dash-prob-header">
                                <label><?= e($label) ?></label>
                                <strong id="lbl_<?= $key ?>"><?= $val ?>%</strong>
                            </div>
                            <input type="range" name="<?= $key ?>" min="0" max="100" step="1" value="<?= $val ?>"
                                   oninput="document.getElementById('lbl_<?= $key ?>').textContent = this.value + '%'">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="dash-form__footer">
                    <button type="submit" class="btn-dash btn-dash--primary btn-dash--lg">
                        <i class="ph ph-floppy-disk"></i>
                        Salvar Humanização
                    </button>
                </div>
            </form>

        </div><!-- /.dash-section -->
        <?php endif; ?>

        <!-- ════════════════════════════════════════════════════════════════════
             ABA: PROMPT
        ═══════════════════════════════════════════════════════════════════════ -->
        <?php if ($aba === 'prompt'): ?>
        <?php
        // Templates reutilizaveis no escopo da aba Prompt
        $promptTemplates = [
            ['🤖 Atendente', "Você é um atendente brasileiro extremamente humano, natural, simpático e espontâneo conversando pelo WhatsApp.\n\nSeu objetivo é criar conexão, gerar confiança, manter conversa natural e conduzir para conversão de forma leve.\n\nJeito de conversar:\n- Informal, leve, direto e espontâneo\n- Escreva como alguém digitando no celular\n- Prefira respostas curtas (até 25 palavras)\n- Use poucos emojis, não em toda mensagem\n- Às vezes escreva de forma imperfeita\n- Use gírias leves com moderação\n\nRegras:\n- Nunca diga que é IA, bot ou assistente virtual\n- Nunca escreva como suporte técnico robótico\n- Nunca use linguagem corporativa ou formal\n- Varie bastante as respostas, evite repetir padrões\n\nEvite frases como: 'aguarde', 'sua solicitação foi recebida', 'prezado cliente'.\nPrefira: 'deixa eu ver aqui', 'já te explico', 'fica tranquilo', 'vou te ajudar nisso'.\n\nAdapte o tom conforme o cliente:\n- Curioso → explique de forma simples\n- Desconfiado → passe segurança naturalmente\n- Querendo preço → responda direto\n- Irritado → seja calmo e compreensivo\n- Animado → acompanhe a energia\n\nSeu objetivo final é fazer o cliente sentir que está conversando com uma pessoa real, simpática, eficiente e confiável."],
            ['🏪 Vendas', "Você é um vendedor brasileiro extremamente humano, persuasivo, espontâneo e natural conversando pelo WhatsApp.\n\nSeu objetivo é criar conexão, despertar interesse, conduzir a conversa naturalmente e converter em venda.\n\nJeito de conversar:\n- Informal, leve, direto e convincente\n- Escreva como alguém digitando no celular\n- Prefira mensagens curtas (até 25 palavras)\n- Use poucos emojis, não em toda mensagem\n- Às vezes escreva de forma imperfeita\n\nRegras:\n- Nunca diga que é IA, bot ou assistente virtual\n- Nunca escreva textos enormes\n- Nunca pareça vendedor desesperado ou agressivo\n- Varie bastante as respostas\n\nEvite: 'prezado cliente', 'aguarde um momento', 'estamos analisando'.\nPrefira: 'te mostro', 'fica muito bom', 'isso facilita demais', 'vale muito a pena'.\n\nSe perguntarem valor: responda simples e destaque o benefício junto.\nExemplo: 'fica R\$49, mas já sai pronto e funcionando'\n\nAdapte o tom conforme o cliente:\n- Curioso → explique de forma leve\n- Animado → acompanhe a energia\n- Desconfiado → passe segurança\n- Indeciso → mostre benefícios sem pressionar\n- Direto → responda objetivo\n\nA venda deve acontecer naturalmente. Nunca force logo no início.\n\nSeu objetivo final é fazer o cliente sentir confiança, interesse e vontade de comprar naturalmente."],
            ['📋 Suporte', "Você é um atendente de suporte brasileiro extremamente humano, paciente, simpático e natural conversando pelo WhatsApp.\n\nSeu objetivo é ajudar o cliente, resolver problemas, transmitir calma e gerar confiança.\n\nJeito de conversar:\n- Simples, humano, leve e direto\n- Escreva como alguém digitando no celular\n- Prefira mensagens curtas (até 30 palavras)\n- Explique de forma simples, sem linguagem técnica\n- Use poucos emojis, não em toda mensagem\n\nRegras:\n- Nunca diga que é IA, bot ou assistente virtual\n- Nunca culpe o cliente\n- Nunca soe frio ou mecânico\n- Varie bastante as respostas\n\nEvite: 'sua solicitação foi registrada', 'aguarde atendimento', 'estamos verificando'.\nPrefira: 'deixa eu ver isso', 'já vou te ajudar', 'isso tem solução sim', 'fica tranquilo'.\n\nSe precisar explicar algo técnico: simplifique ao máximo, fale como alguém ajudando um amigo.\nSe houver erro: admita naturalmente, tranquilize o cliente e informe que está sendo resolvido.\n\nAdapte o tom conforme o cliente:\n- Irritado → seja calmo e compreensivo\n- Perdido → explique com muita calma\n- Com pressa → seja objetivo\n- Desconfiado → passe segurança\n\nSeu objetivo final é fazer o cliente sentir que está sendo atendido por uma pessoa real, paciente e confiável."],
            ['💬 Informal', "Você é uma pessoa brasileira carinhosa, espontânea, divertida e totalmente natural conversando em um chat privado.\n\nSeu objetivo é criar conexão emocional, manter conversa envolvente e parecer totalmente humana.\n\nJeito de escrever:\n- Curto, informal, natural e leve\n- Responda como alguém digitando no celular\n- Prefira respostas curtas (até 20 palavras)\n- Use poucos emojis, não em toda mensagem\n- Às vezes escreva de forma imperfeita\n- Use gírias leves com moderação\n\nRegras:\n- Nunca diga que é IA, bot ou assistente virtual\n- Nunca escreva mensagens longas\n- Nunca pareça um personagem exagerado\n- Varie bastante as respostas, sem repetir padrões\n\nQuando fizer sentido:\n- Demonstre carinho naturalmente\n- Faça brincadeiras leves\n- Mostre interesse genuíno na conversa\n- Seja espontâneo e envolvente\n\nAdapte o tom conforme a conversa:\n- Carinhoso → retribua naturalmente\n- Brincando → entre na brincadeira\n- Triste → demonstre atenção\n- Distante → puxe assunto leve\n\nSe perguntarem se você é real: responda naturalmente, nunca como robô.\nExemplos: 'claro né kkk', 'sou sim', 'ué vc acha que não?'\n\nSeu objetivo final é fazer a pessoa sentir que está conversando com alguém real, simpático e naturalmente envolvente."],
        ];
        // Multi-sessao pago: exibe tabs de sessao se ha mais de 1
        $hasMultiSession = (!$isFreePlan && count($sessoesWA) > 1);
        ?>
        <div class="dash-section">

            <div class="dash-section__header">
                <h1 class="dash-section__title">
                    <i class="ph ph-chat-teardrop-text"></i>
                    Prompt da IA
                </h1>
                <p class="dash-section__sub">
                    <?php if ($hasMultiSession): ?>
                    Cada sessão de WhatsApp possui seu próprio prompt. Configure a personalidade de cada número separadamente.
                    <?php else: ?>
                    Defina a personalidade e instruções da sua IA.
                    <?php endif; ?>
                </p>
            </div>

            <?php if ($hasMultiSession): ?>
            <!-- ── Tabs de Sessão (plano pago, múltiplas sessões) ── -->
            <div class="sp-tabs-bar" role="tablist" aria-label="Sessões">
                <?php foreach ($sessoesWA as $spIdx => $spSessao): ?>
                <?php
                    $spSid     = $spSessao['session_id'];
                    $spLabel   = $spSessao['apelido'] ?? ('Sessão ' . ($spIdx + 1));
                    $spStatus  = $spSessao['status'];
                    $spDotCls  = $spStatus === 'conectado' ? 'green' : ($spStatus === 'aguardando_qr' ? 'yellow' : 'red');
                ?>
                <button class="sp-tab<?= $spIdx === 0 ? ' sp-tab--active' : '' ?>"
                        role="tab"
                        aria-selected="<?= $spIdx === 0 ? 'true' : 'false' ?>"
                        aria-controls="spPanel_<?= e($spSid) ?>"
                        onclick="spSwitchTab('<?= e($spSid) ?>', this)">
                    <span class="sp-tab__dot sp-tab__dot--<?= $spDotCls ?>"></span>
                    <?= e($spLabel) ?>
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <form method="POST" class="dash-form" id="promptForm">
                <input type="hidden" name="acao" value="salvar_prompt">

                <?php if ($hasMultiSession): ?>
                <!-- ── Painéis de prompt por sessão ── -->
                <?php foreach ($sessoesWA as $spIdx => $spSessao): ?>
                <?php
                    $spSid        = $spSessao['session_id'];
                    $spLabel      = $spSessao['apelido'] ?? ('Sessão ' . ($spIdx + 1));
                    $spChave      = 'session_prompt_' . $spSid;
                    // Prompt desta sessão: usa o session_prompt_SID se existir, senão herda o global
                    $spPromptVal  = isset($cfg[$spChave]) && $cfg[$spChave] !== ''
                                    ? $cfg[$spChave]
                                    : $cfg['system_prompt'];
                    $spTaId       = 'spTA_' . $spSid;
                    $spCntId      = 'spCount_' . $spSid;
                ?>
                <div class="sp-panel<?= $spIdx === 0 ? ' sp-panel--active' : '' ?>"
                     id="spPanel_<?= e($spSid) ?>"
                     role="tabpanel">

                    <div class="dash-card sp-card">
                        <div class="dash-card__header">
                            <i class="ph ph-file-text"></i>
                            <h2>Prompt — <span class="sp-card__session-label"><?= e($spLabel) ?></span></h2>
                            <span class="sp-card__badge sp-badge--session">
                                <i class="ph ph-device-mobile"></i>
                                Sessão <?= $spIdx + 1 ?>
                            </span>
                        </div>
                        <p class="dash-card__desc">Configure a personalidade da IA para este número. Cada sessão pode ter um perfil diferente.</p>

                        <div class="dash-prompt-templates">
                            <span class="dash-prompt-templates__label">Templates:</span>
                            <?php foreach ($promptTemplates as [$tLabel, $tTexto]): ?>
                            <button type="button" class="dash-tpl-btn"
                                    onclick="spAplicarTemplate('<?= e($spSid) ?>', <?= htmlspecialchars(json_encode($tTexto), ENT_QUOTES) ?>)">
                                <?= e($tLabel) ?>
                            </button>
                            <?php endforeach; ?>
                        </div>

                        <div class="dash-textarea-wrap">
                            <textarea name="session_prompts[<?= e($spSid) ?>]"
                                      id="<?= e($spTaId) ?>"
                                      class="dash-textarea"
                                      rows="12"
                                      maxlength="<?= $freeLimits['max_tokens_por_prompt'] ?>"
                                      placeholder="Descreva a personalidade da IA para esta sessão..."><?= e($spPromptVal) ?></textarea>
                            <div class="dash-textarea-counter">
                                <span id="<?= e($spCntId) ?>"><?= strlen($spPromptVal) ?></span>/<?= number_format($freeLimits['max_tokens_por_prompt'], 0, ',', '.') ?> caracteres
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php else: ?>
                <!-- ── Sessão única (free ou 1 sessão) ── -->
                <div class="dash-card">
                    <div class="dash-card__header">
                        <i class="ph ph-file-text"></i>
                        <h2>Prompt de Sistema</h2>
                    </div>
                    <p class="dash-card__desc">Descreva quem é sua IA, como ela deve se comportar e o que ela pode ou não fazer.</p>

                    <div class="dash-prompt-templates">
                        <span class="dash-prompt-templates__label">Templates:</span>
                        <?php foreach ($promptTemplates as [$tLabel, $tTexto]): ?>
                        <button type="button" class="dash-tpl-btn"
                                onclick="aplicarTemplate(<?= htmlspecialchars(json_encode($tTexto), ENT_QUOTES) ?>)">
                            <?= e($tLabel) ?>
                        </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="dash-textarea-wrap">
                        <textarea name="system_prompt" id="systemPromptTA"
                                  class="dash-textarea"
                                  rows="12"
                                  maxlength="<?= $freeLimits['max_tokens_por_prompt'] ?>"
                                  placeholder="Descreva aqui a personalidade e instruções da sua IA..."><?= e($cfg['system_prompt']) ?></textarea>
                        <div class="dash-textarea-counter">
                            <span id="promptCount"><?= strlen($cfg['system_prompt']) ?></span>/<?= number_format($freeLimits['max_tokens_por_prompt'], 0, ',', '.') ?> caracteres
                            <?php if ($isFreePlan): ?><span class="dash-textarea-counter__free"> · <i class="ph ph-lock-simple"></i> Plano Grátis: máx. <?= number_format($freeLimits['max_tokens_por_prompt'], 0, ',', '.') ?> chars — <a href="/planos">upgrade</a></span><?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="dash-form__footer">
                    <button type="submit" class="btn-dash btn-dash--primary btn-dash--lg">
                        <i class="ph ph-floppy-disk"></i>
                        Salvar Prompt
                    </button>
                </div>
            </form>

        </div><!-- /.dash-section -->

        <style>
        /* ── Session Prompt Tabs ────────────────────────────────────────────── */
        .sp-tabs-bar {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 16px;
            padding: 4px;
            background: var(--dash-surface);
            border: 1px solid var(--dash-border);
            border-radius: 12px;
        }
        .sp-tab {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 16px;
            border: none;
            background: transparent;
            border-radius: 9px;
            font-size: .82rem;
            font-weight: 600;
            color: var(--dash-muted);
            cursor: pointer;
            transition: background .17s, color .17s;
            white-space: nowrap;
        }
        .sp-tab:hover {
            background: var(--dash-accent-dim);
            color: var(--dash-accent-dk);
        }
        .sp-tab--active {
            background: var(--dash-accent);
            color: #fff;
            box-shadow: 0 2px 8px rgba(0,192,96,.25);
        }
        .sp-tab--active:hover { filter: brightness(1.08); }
        .sp-tab__dot {
            width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0;
        }
        .sp-tab__dot--green  { background: #22c55e; }
        .sp-tab__dot--yellow { background: #f59e0b; }
        .sp-tab__dot--red    { background: #ef4444; }
        .sp-tab--active .sp-tab__dot--green  { background: #fff; box-shadow: 0 0 0 1.5px rgba(255,255,255,.5); }
        .sp-tab--active .sp-tab__dot--yellow { background: #fde68a; }
        .sp-tab--active .sp-tab__dot--red    { background: #fca5a5; }

        /* Painéis de sessão */
        .sp-panel { display: none; }
        .sp-panel--active { display: block; animation: fadeUp .25s ease both; }

        /* Card de sessão */
        .sp-card { border-top: 3px solid var(--dash-accent); }
        .sp-card__session-label { color: var(--dash-accent-dk); }
        .sp-card__badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: .7rem;
            font-weight: 700;
            padding: 2px 9px;
            border-radius: 20px;
            margin-left: auto;
        }
        .sp-badge--session {
            background: var(--dash-accent-dim);
            color: var(--dash-accent-dk);
            border: 1px solid rgba(0,192,96,.2);
        }
        </style>

        <script>
        // ── Session Prompt Tabs ──────────────────────────────────────────────
        function spSwitchTab(sid, btn) {
            // Esconde todos os paineis e desativa todas as tabs
            document.querySelectorAll('.sp-panel').forEach(function(p) {
                p.classList.remove('sp-panel--active');
            });
            document.querySelectorAll('.sp-tab').forEach(function(t) {
                t.classList.remove('sp-tab--active');
                t.setAttribute('aria-selected', 'false');
            });
            // Ativa painel e tab selecionados
            var panel = document.getElementById('spPanel_' + sid);
            if (panel) panel.classList.add('sp-panel--active');
            if (btn) {
                btn.classList.add('sp-tab--active');
                btn.setAttribute('aria-selected', 'true');
            }
        }

        // ── Contadores de caracteres para textareas de sessao ────────────────
        (function() {
            document.querySelectorAll('[id^="spTA_"]').forEach(function(ta) {
                var sid   = ta.id.replace('spTA_', '');
                var cnt   = document.getElementById('spCount_' + sid);
                if (!cnt) return;
                ta.addEventListener('input', function() { cnt.textContent = ta.value.length; });
            });

            // Contador do prompt global
            var ta = document.getElementById('systemPromptTA');
            var counter = document.getElementById('promptCount');
            if (ta && counter) {
                ta.addEventListener('input', function() { counter.textContent = ta.value.length; });
            }
        })();

        // ── Aplicar template em um textarea de sessao especifico ─────────────
        function spAplicarTemplate(sid, texto) {
            var ta = document.getElementById('spTA_' + sid);
            if (!ta) return;
            if (ta.value.trim() && !confirm('Substituir o prompt desta sessão pelo template?')) return;
            ta.value = texto;
            ta.dispatchEvent(new Event('input'));
            ta.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        // ── Template global (sessao unica) ───────────────────────────────────
        function aplicarTemplate(texto) {
            var ta = document.getElementById('systemPromptTA');
            if (!ta) return;
            if (ta.value.trim() && !confirm('Substituir o prompt atual pelo template?')) return;
            ta.value = texto;
            ta.dispatchEvent(new Event('input'));
            ta.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        </script>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════════════════════════════
             ABA: MÍDIA IA
        ═══════════════════════════════════════════════════════════════════════ -->
        <?php if ($aba === 'midia'): ?>
        <div class="dash-section">

            <div class="dash-section__header">
                <h1 class="dash-section__title">
                    <i class="ph ph-image-square"></i>
                    Mídia IA
                </h1>
                <p class="dash-section__sub">Cadastre imagens, vídeos e áudios que a IA enviará automaticamente ao detectar uma palavra-gatilho.</p>
            </div>

            <!-- ── Card: Adicionar Mídia Premium ── -->
            <div class="dash-card midia-upload-card">
                <div class="dash-card__header">
                    <i class="ph ph-plus-circle"></i>
                    <h2>Adicionar Mídia</h2>
                    <?php if (!empty($freeBlockedMidiaTypes)): ?><span class="dash-free-badge"><i class="ph ph-lock-simple"></i> Tipos limitados</span><?php endif; ?>
                </div>

                <?php if (!empty($freeBlockedMidiaTypes)): ?>
                <div class="dash-free-notice">
                    <i class="ph ph-info"></i>
                    <span>Seu plano permite apenas: <strong><?= implode(', ', $midiaPermitidos) ?></strong>. <?= implode(' e ', array_map('ucfirst', $freeBlockedMidiaTypes)) ?> não está disponível.</span>
                </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" class="dash-form midia-form" id="midiaForm">
                    <input type="hidden" name="acao" value="add_midia">

                    <!-- Linha de campos -->
                    <div class="midia-fields-row">
                        <div class="midia-field">
                            <label class="midia-field__label">
                                <i class="ph ph-lightning"></i>
                                Palavra-gatilho
                            </label>
                            <input type="text" name="gatilho" id="midiaGatilho" class="dash-input midia-input"
                                   placeholder="ex: cardapio, foto_praia" required
                                   oninput="atualizarExemploGatilho()">
                        </div>
                        <div class="midia-field">
                            <label class="midia-field__label">
                                <i class="ph ph-pencil-line"></i>
                                Descrição <span class="midia-field__opt">opcional</span>
                            </label>
                            <input type="text" name="descricao" class="dash-input midia-input" placeholder="Ex: Foto do cardápio do almoço">
                        </div>
                        <div class="midia-field midia-field--sm">
                            <label class="midia-field__label">
                                <i class="ph ph-file-image"></i>
                                Tipo de mídia
                            </label>
                            <div class="midia-tipo-btns" id="midiaTipoBtns">
                                <button type="button" class="midia-tipo-btn active" data-tipo="imagem" onclick="selecionarTipoMidia('imagem')">
                                    <i class="ph ph-image"></i><span>Imagem</span>
                                </button>
                                <button type="button" class="midia-tipo-btn <?= in_array('video', $freeBlockedMidiaTypes) ? 'midia-tipo-btn--locked' : '' ?>" data-tipo="video"
                                        onclick="<?= in_array('video', $freeBlockedMidiaTypes) ? "mostrarAvisoFree('Upload de vídeo não está disponível no seu plano.')" : "selecionarTipoMidia('video')" ?>">
                                    <i class="ph ph-film-strip"></i><span>Vídeo<?= in_array('video', $freeBlockedMidiaTypes) ? ' 🔒' : '' ?></span>
                                </button>
                                <button type="button" class="midia-tipo-btn <?= in_array('audio', $freeBlockedMidiaTypes) ? 'midia-tipo-btn--locked' : '' ?>" data-tipo="audio"
                                        onclick="<?= in_array('audio', $freeBlockedMidiaTypes) ? "mostrarAvisoFree('Upload de áudio não está disponível no seu plano.')" : "selecionarTipoMidia('audio')" ?>">
                                    <i class="ph ph-waveform"></i><span>Áudio<?= in_array('audio', $freeBlockedMidiaTypes) ? ' 🔒' : '' ?></span>
                                </button>
                            </div>
                            <input type="hidden" name="tipo" id="midiaHiddenTipo" value="imagem">
                        </div>
                        <div class="midia-field midia-field--sm">
                            <label class="midia-field__label">
                                <i class="ph ph-robot"></i>
                                Tipo de gatilho
                            </label>
                            <select name="tipo_gatilho" class="dash-select midia-input" id="tipoGatilhoSelect" onchange="toggleGatilhoInfo()">
                                <option value="direto">⚡ Direto (palavra-chave)</option>
                                <option value="prompt">🤖 Pelo Prompt (marcador IA)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Drop Zone Premium -->
                    <div class="midia-dropzone-wrap">
                        <div class="midia-dropzone" id="midiaDropZone" onclick="document.getElementById('arquivoMidia').click()">
                            <div class="midia-dropzone__inner">
                                <div class="midia-dropzone__icon-wrap">
                                    <i class="ph ph-cloud-arrow-up midia-dropzone__icon"></i>
                                </div>
                                <p class="midia-dropzone__title">Arraste e solte ou clique para selecionar</p>
                                <p class="midia-dropzone__hint" id="midiaDropHint">Imagens: JPG, PNG, WebP, GIF — até 20MB</p>
                                <span class="midia-dropzone__btn">
                                    <i class="ph ph-folder-open"></i>
                                    Escolher arquivo
                                </span>
                            </div>
                        </div>
                        <input type="file" name="arquivo" id="arquivoMidia" style="display:none;" accept="image/*" required>
                    </div>

                    <!-- Preview do arquivo selecionado — card pequeno vertical igual à galeria -->
                    <div class="midia-preview-area" id="midiaPreviewArea" style="display:none;">
                        <p class="midia-preview-label"><i class="ph ph-check-circle"></i> Arquivo selecionado — revise antes de cadastrar</p>
                        <div class="midia-preview-grid">
                            <div class="midia-card-premium midia-preview-single" id="midiaPreviewCard">
                                <div class="midia-card-preview" id="midiaPreviewMedia"></div>
                                <div class="midia-card-info">
                                    <span class="midia-preview-filename" id="midiaPreviewInfo"></span>
                                    <button type="button" class="midia-preview-remove-btn" onclick="removerArquivoSelecionado()">
                                        <i class="ph ph-trash"></i> Remover
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Info: Gatilho pelo Prompt -->
                    <div id="gatilhoPromptInfo" class="dash-gatilho-info" style="display:none;">
                        <div class="dash-gatilho-info__header">
                            <i class="ph ph-robot"></i>
                            <strong>Como usar o gatilho pelo Prompt (IA)</strong>
                        </div>
                        <p>Adicione instruções como estas no seu <strong>System Prompt</strong>:</p>
                        <pre class="dash-gatilho-info__code">Se o usuário pedir para ver o arquivo, use o marcador abaixo:
- Para enviar: [ENVIAR:<span id="gatilhoNomeExemplo">nome_do_gatilho</span>]

Você pode combinar texto com o marcador:
"olha aqui 😊 [ENVIAR:<span id="gatilhoNomeExemplo2">nome_do_gatilho</span>]"

Nunca escreva o marcador entre aspas ou de outra forma.</pre>
                        <p class="dash-gatilho-info__hint"><i class="ph ph-info"></i> O nome do gatilho cadastrado acima é o que deve ser usado dentro do marcador <code>[ENVIAR:gatilho]</code>.</p>
                    </div>

                    <!-- Botão de envio -->
                    <div class="midia-submit-row">
                        <div id="midiaAvisoFree" class="dash-free-notice" style="display:none;">
                            <i class="ph ph-lock-simple"></i><span></span>
                        </div>
                        <button type="submit" class="btn-dash btn-dash--primary btn-dash--lg midia-submit-btn" id="midiaBtnEnviar">
                            <i class="ph ph-upload-simple"></i>
                            Cadastrar Mídia
                        </button>
                    </div>
                </form>
            </div>

            <!-- ── Card: Galeria de Mídias Cadastradas ── -->
            <div class="dash-card">
                <div class="dash-card__header">
                    <i class="ph ph-images"></i>
                    <h2>Mídias Cadastradas</h2>
                    <span class="dash-card__count"><?= count($midias) ?></span>
                </div>

                <?php if (empty($midias)): ?>
                <div class="dash-empty midia-empty-state">
                    <div class="midia-empty-icon">
                        <i class="ph ph-image-square"></i>
                    </div>
                    <p class="midia-empty-title">Nenhuma mídia cadastrada</p>
                    <p class="midia-empty-sub">Faça o upload de uma imagem, vídeo ou áudio acima e ela aparecerá aqui.</p>
                </div>
                <?php else: ?>
                <div class="dash-midias-grid midia-galeria-grid">
                    <?php foreach ($midias as $mid): ?>
                    <div class="dash-midia-card midia-card-premium" data-id="<?= (int)$mid['id'] ?>">

                        <!-- Preview com overlay ao hover -->
                        <div class="dash-midia-card__preview midia-card-preview">
                            <?php if ($mid['tipo'] === 'imagem'): ?>
                                <img src="<?= e($mid['caminho']) ?>" alt="<?= e($mid['gatilho']) ?>" loading="lazy">
                            <?php elseif ($mid['tipo'] === 'video'): ?>
                                <video src="<?= e($mid['caminho']) ?>" muted preload="metadata"></video>
                                <div class="dash-midia-card__play midia-play-btn"><i class="ph ph-play-circle"></i></div>
                            <?php else: ?>
                                <div class="dash-midia-card__audio-preview midia-audio-visual">
                                    <div class="midia-audio-bars">
                                        <?php for($b=0;$b<8;$b++): ?><span class="midia-audio-bar" style="animation-delay:<?= $b*0.07 ?>s"></span><?php endfor; ?>
                                    </div>
                                    <i class="ph ph-waveform midia-audio-icon"></i>
                                    <audio controls src="<?= e($mid['caminho']) ?>"></audio>
                                </div>
                            <?php endif; ?>

                            <!-- Overlay de ações (apenas imagem e video) -->
                            <?php if ($mid['tipo'] !== 'audio'): ?>
                            <div class="midia-card-overlay">
                                <a href="<?= e($mid['caminho']) ?>" target="_blank" class="midia-overlay-btn midia-overlay-btn--view" title="Visualizar">
                                    <i class="ph ph-eye"></i>
                                </a>
                                <form method="POST" class="midia-del-form" onsubmit="return confirmDel(event, this)">
                                    <input type="hidden" name="acao" value="del_midia">
                                    <input type="hidden" name="midia_id" value="<?= (int)$mid['id'] ?>">
                                    <button type="submit" class="midia-overlay-btn midia-overlay-btn--del" title="Remover">
                                        <i class="ph ph-trash"></i>
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>

                            <!-- Badge tipo -->
                            <span class="midia-tipo-badge midia-tipo-badge--<?= $mid['tipo'] ?>">
                                <?= $mid['tipo'] === 'imagem' ? '<i class="ph ph-image"></i>' : ($mid['tipo'] === 'video' ? '<i class="ph ph-film-strip"></i>' : '<i class="ph ph-waveform"></i>') ?>
                                <?= ucfirst($mid['tipo']) ?>
                            </span>
                        </div>

                        <!-- Info -->
                        <div class="dash-midia-card__info midia-card-info">
                            <div class="midia-card-info__top">
                                <span class="dash-midia-card__gatilho">
                                    <i class="ph ph-tag"></i>
                                    <?= e($mid['gatilho']) ?>
                                </span>
                                <?php if ($mid['tipo'] === 'audio'): ?>
                                <div class="midia-audio-actions">
                                    <form method="POST" class="midia-del-form" onsubmit="return confirmDel(event, this)" style="margin:0;">
                                        <input type="hidden" name="acao" value="del_midia">
                                        <input type="hidden" name="midia_id" value="<?= (int)$mid['id'] ?>">
                                        <button type="submit" class="midia-audio-btn midia-audio-btn--del" title="Remover">
                                            <i class="ph ph-trash"></i>
                                        </button>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                            <span class="dash-midia-card__badge dash-midia-card__badge--<?= ($mid['tipo_gatilho'] ?? 'direto') === 'prompt' ? 'prompt' : 'direto' ?>">
                                <?php if (($mid['tipo_gatilho'] ?? 'direto') === 'prompt'): ?>
                                    <i class="ph ph-robot"></i> Gatilho pelo Prompt
                                <?php else: ?>
                                    <i class="ph ph-lightning"></i> Gatilho Direto
                                <?php endif; ?>
                            </span>
                            <?php if ($mid['descricao']): ?>
                            <span class="dash-midia-card__desc"><?= e($mid['descricao']) ?></span>
                            <?php endif; ?>
                            <span class="dash-midia-card__date">
                                <i class="ph ph-calendar-blank"></i>
                                <?= date('d/m/Y', strtotime($mid['criado_em'])) ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div><!-- /.dash-section -->

        <!-- Lightbox / Modal de confirmação de delete -->
        <div class="midia-lightbox" id="midiaLightbox" onclick="fecharLightbox()">
            <button class="midia-lightbox__close" onclick="fecharLightbox()"><i class="ph ph-x"></i></button>
            <div class="midia-lightbox__content" onclick="event.stopPropagation()">
                <img id="midiaLightboxImg" src="" alt="" style="display:none;">
                <video id="midiaLightboxVid" controls style="display:none;"></video>
            </div>
        </div>

        <style>
        /* ════════════════════════════════════════════════════════════════════
           MÍDIA IA — SISTEMA PREMIUM DE UPLOAD
        ════════════════════════════════════════════════════════════════════ */

        /* ── Tipo de mídia: botões visuais ── */
        .midia-fields-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 14px;
            margin-bottom: 20px;
        }
        @media(max-width:900px){ .midia-fields-row { grid-template-columns: 1fr 1fr; } }
        @media(max-width:560px){ .midia-fields-row { grid-template-columns: 1fr; } }

        .midia-field { display: flex; flex-direction: column; gap: 7px; }
        .midia-field__label {
            font-size: .78rem; font-weight: 700;
            letter-spacing: .04em; text-transform: uppercase;
            color: var(--dash-muted);
            display: flex; align-items: center; gap: 5px;
        }
        .midia-field__label i { font-size: .9rem; }
        .midia-field__opt { font-weight: 400; text-transform: none; font-size: .72rem; color: #b0b8c4; letter-spacing: 0; }
        .midia-input { transition: border-color .18s, box-shadow .18s, transform .12s; }
        .midia-input:focus { transform: translateY(-1px); }

        .midia-tipo-btns {
            display: flex; gap: 6px; flex-wrap: wrap;
        }
        .midia-tipo-btn {
            flex: 1; min-width: 62px;
            display: flex; flex-direction: column; align-items: center; gap: 4px;
            padding: 9px 6px; border-radius: 10px;
            border: 1.5px solid var(--dash-border);
            background: var(--dash-bg);
            color: var(--dash-muted);
            font-size: .72rem; font-weight: 600;
            cursor: pointer; transition: all .2s cubic-bezier(.34,1.56,.64,1);
        }
        .midia-tipo-btn i { font-size: 1.15rem; }
        .midia-tipo-btn:hover { border-color: var(--dash-accent); color: var(--dash-accent); transform: translateY(-2px); }
        .midia-tipo-btn.active {
            border-color: var(--dash-accent);
            background: var(--dash-accent-dim);
            color: var(--dash-accent-dk);
            box-shadow: 0 2px 10px rgba(0,192,96,.18);
            transform: translateY(-2px);
        }
        .midia-tipo-btn--locked {
            opacity: .55; cursor: not-allowed;
            background: var(--dash-surface);
        }
        .midia-tipo-btn--locked:hover { transform: none; border-color: var(--dash-border); color: var(--dash-muted); }

        /* ── Drop Zone ── */
        .midia-dropzone-wrap { margin-bottom: 16px; }

        .midia-dropzone {
            width: 100%;
            padding: 36px 24px;
            border: 2px dashed rgba(0,192,96,.35);
            border-radius: 14px;
            text-align: center;
            cursor: pointer;
            background: rgba(0,192,96,.025);
            transition: background .25s, border-color .25s, transform .18s;
            position: relative;
            overflow: hidden;
        }
        .midia-dropzone::before {
            content: '';
            position: absolute; inset: 0;
            background: radial-gradient(ellipse at 50% 0%, rgba(0,192,96,.07) 0%, transparent 70%);
            opacity: 0; transition: opacity .3s;
        }
        .midia-dropzone:hover, .midia-dropzone.dragover {
            border-color: var(--dash-accent);
            background: rgba(0,192,96,.055);
            transform: translateY(-2px);
        }
        .midia-dropzone:hover::before, .midia-dropzone.dragover::before { opacity: 1; }
        .midia-dropzone.dragover { border-style: solid; }

        .midia-dropzone__inner { position: relative; z-index: 1; display: flex; flex-direction: column; align-items: center; gap: 8px; }

        .midia-dropzone__icon-wrap {
            width: 64px; height: 64px; border-radius: 18px;
            background: linear-gradient(135deg, var(--dash-accent-dim), rgba(0,192,96,.08));
            display: flex; align-items: center; justify-content: center;
            border: 1.5px solid rgba(0,192,96,.22);
            margin-bottom: 4px;
            transition: transform .3s cubic-bezier(.34,1.56,.64,1);
        }
        .midia-dropzone:hover .midia-dropzone__icon-wrap { transform: scale(1.1) translateY(-2px); }
        .midia-dropzone.dragover .midia-dropzone__icon-wrap { transform: scale(1.15) rotate(-5deg); }

        .midia-dropzone__icon {
            font-size: 2rem; color: var(--dash-accent);
            transition: transform .3s;
        }
        .midia-dropzone.dragover .midia-dropzone__icon { transform: translateY(-4px); }

        .midia-dropzone__title { font-size: .9rem; font-weight: 700; color: var(--dash-text); margin: 0; }
        .midia-dropzone__hint { font-size: .78rem; color: var(--dash-muted); margin: 0; }

        .midia-dropzone__btn {
            display: inline-flex; align-items: center; gap: 6px;
            margin-top: 8px; padding: 8px 18px;
            border-radius: 8px;
            background: var(--dash-accent); color: #fff;
            font-size: .8rem; font-weight: 700;
            transition: background .18s, box-shadow .18s;
            box-shadow: 0 2px 8px rgba(0,192,96,.3);
        }
        .midia-dropzone:hover .midia-dropzone__btn { background: var(--dash-accent-dk); box-shadow: 0 4px 14px rgba(0,192,96,.4); }

        /* ── Preview do arquivo selecionado ── */
        /* ── Preview do arquivo selecionado — card pequeno vertical ── */
        .midia-preview-area { margin-top: 16px; }

        @keyframes midiaCardIn {
            from { opacity: 0; transform: scale(.85) translateY(12px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }

        .midia-preview-label {
            font-size: .78rem; font-weight: 600;
            color: var(--dash-accent-dk);
            display: flex; align-items: center; gap: 6px;
            margin-bottom: 12px;
        }
        .midia-preview-label i { font-size: .9rem; }

        .midia-preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 16px;
        }

        .midia-preview-single {
            max-width: 200px;
            border: 1.5px solid var(--dash-accent) !important;
            box-shadow: 0 4px 18px rgba(0,192,96,.18) !important;
        }

        .midia-preview-filename {
            font-size: .72rem;
            font-weight: 600;
            color: var(--dash-text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
        }

        .midia-preview-remove-btn {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: .72rem; font-weight: 700;
            background: rgba(239,68,68,.1); color: #b91c1c;
            border: 1px solid rgba(239,68,68,.25);
            border-radius: 6px; padding: 4px 10px;
            cursor: pointer; transition: background .18s;
            margin-top: 2px;
        }
        .midia-preview-remove-btn:hover { background: rgba(239,68,68,.2); }

        /* ── Submit row ── */
        .midia-submit-row {
            display: flex; align-items: center; gap: 14px;
            justify-content: flex-end; flex-wrap: wrap;
            padding-top: 8px; border-top: 1px solid var(--dash-border);
        }
        .midia-submit-btn { min-width: 180px; justify-content: center; }

        /* ── Galeria grid (mídias cadastradas) ── */
        .midia-galeria-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
            gap: 16px;
        }
        @media(max-width:480px){ .midia-galeria-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; } }

        /* ── Card premium de mídia cadastrada ── */
        .midia-card-premium {
            border-radius: 14px; overflow: hidden;
            border: 1px solid var(--dash-border);
            background: var(--dash-surface);
            transition: box-shadow .22s, transform .22s;
            animation: midiaCardIn .4s cubic-bezier(.34,1.56,.64,1) both;
        }
        .midia-card-premium:hover {
            box-shadow: 0 8px 28px rgba(0,0,0,.12);
            transform: translateY(-4px);
        }

        .midia-card-preview {
            position: relative; overflow: hidden;
            aspect-ratio: 1;
            background: #0a0f12;
        }
        .midia-card-preview img,
        .midia-card-preview video {
            width: 100%; height: 100%; object-fit: cover;
            transition: transform .4s ease;
        }
        .midia-card-premium:hover .midia-card-preview img,
        .midia-card-premium:hover .midia-card-preview video { transform: scale(1.06); }

        /* Audio visual bars */
        .midia-audio-visual {
            width: 100%; height: 100%;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: 10px;
            background: linear-gradient(135deg, #0d1f14, #0a1a20);
            padding: 12px; box-sizing: border-box;
        }
        .midia-audio-bars { display: flex; align-items: flex-end; gap: 3px; height: 36px; }
        .midia-audio-bar {
            width: 4px; border-radius: 2px;
            background: var(--dash-accent);
            animation: audioBounce 1s ease-in-out infinite alternate;
        }
        .midia-audio-bar:nth-child(1){ height: 60%; } .midia-audio-bar:nth-child(2){ height: 90%; }
        .midia-audio-bar:nth-child(3){ height: 40%; } .midia-audio-bar:nth-child(4){ height: 100%; }
        .midia-audio-bar:nth-child(5){ height: 55%; } .midia-audio-bar:nth-child(6){ height: 80%; }
        .midia-audio-bar:nth-child(7){ height: 35%; } .midia-audio-bar:nth-child(8){ height: 70%; }
        @keyframes audioBounce {
            from { transform: scaleY(.3); opacity: .5; }
            to   { transform: scaleY(1);  opacity: 1; }
        }
        .midia-audio-icon { font-size: 1.5rem; color: var(--dash-accent); opacity: .7; }
        .midia-audio-visual audio { width: 100%; height: 28px; }

        /* Play button overlay */
        .midia-play-btn {
            position: absolute; inset: 0;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 2.8rem;
            text-shadow: 0 2px 12px rgba(0,0,0,.6);
            opacity: 0; transition: opacity .22s;
        }
        .midia-card-premium:hover .midia-play-btn { opacity: 1; }

        /* Tipo badge */
        .midia-tipo-badge {
            position: absolute; top: 8px; left: 8px; z-index: 5;
            display: inline-flex; align-items: center; gap: 4px;
            font-size: .68rem; font-weight: 700; padding: 3px 8px;
            border-radius: 20px; backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,.25);
            color: #fff; letter-spacing: .02em;
        }
        .midia-tipo-badge--imagem { background: rgba(0,192,96,.7); }
        .midia-tipo-badge--video  { background: rgba(99,102,241,.75); }
        .midia-tipo-badge--audio  { background: rgba(245,158,11,.75); }

        /* Overlay de ações (hover) */
        .midia-card-overlay {
            position: absolute; inset: 0; z-index: 10;
            display: flex; align-items: center; justify-content: center; gap: 10px;
            background: rgba(0,0,0,.48); backdrop-filter: blur(3px);
            opacity: 0; transition: opacity .22s;
        }
        .midia-card-premium:hover .midia-card-overlay { opacity: 1; }

        .midia-overlay-btn {
            width: 42px; height: 42px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            border: 2px solid rgba(255,255,255,.55);
            color: #fff; font-size: 1.15rem;
            cursor: pointer; background: rgba(255,255,255,.15);
            transition: background .18s, transform .18s, border-color .18s;
            text-decoration: none;
            transform: scale(.85);
        }
        .midia-card-premium:hover .midia-overlay-btn { transform: scale(1); }
        .midia-overlay-btn:hover { background: rgba(255,255,255,.3); border-color: #fff; transform: scale(1.08) !important; }
        .midia-overlay-btn--del { background: rgba(239,68,68,.5); border-color: rgba(255,100,100,.6); }
        .midia-overlay-btn--del:hover { background: rgba(239,68,68,.85); }
        .midia-del-form { margin: 0; }

        /* Card info area */
        .midia-card-info {
            padding: 10px 12px;
            display: flex; flex-direction: column; gap: 5px;
        }
        .midia-card-info__top {
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
        }
        .midia-card-info__top .dash-midia-card__gatilho { flex: 1; min-width: 0; }
        .midia-card-info .dash-midia-card__date {
            display: flex; align-items: center; gap: 4px;
        }
        .midia-card-info .dash-midia-card__date i { font-size: .8rem; }

        /* Botões de ação inline para áudio */
        .midia-audio-actions {
            display: flex; align-items: center; gap: 6px; flex-shrink: 0;
        }
        .midia-audio-btn {
            width: 30px; height: 30px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: .95rem; cursor: pointer;
            border: 1px solid var(--dash-border);
            background: var(--dash-bg);
            color: var(--dash-muted);
            text-decoration: none;
            transition: background .15s, color .15s;
        }
        .midia-audio-btn:hover { background: var(--dash-accent-dim); color: var(--dash-accent-dk); }
        .midia-audio-btn--del { color: var(--color-danger); border-color: rgba(239,68,68,.3); background: rgba(239,68,68,.07); }
        .midia-audio-btn--del:hover { background: var(--color-danger); color: #fff; }

        /* Estado vazio melhorado */
        .midia-empty-state { text-align: center; padding: 48px 20px !important; }
        .midia-empty-icon {
            width: 80px; height: 80px; border-radius: 50%;
            background: var(--dash-accent-dim);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px;
            border: 1px solid rgba(0,192,96,.2);
        }
        .midia-empty-icon i { font-size: 2.2rem; color: var(--dash-accent); }
        .midia-empty-title { font-size: 1rem; font-weight: 700; color: var(--dash-text); margin: 0 0 4px; }
        .midia-empty-sub { font-size: .83rem; color: var(--dash-muted); margin: 0; }

        /* Lightbox */
        .midia-lightbox {
            display: none;
            position: fixed; inset: 0; z-index: 9999;
            background: rgba(0,0,0,.92); backdrop-filter: blur(14px);
            align-items: center; justify-content: center;
            animation: fadeInLightbox .22s ease;
        }
        .midia-lightbox.open { display: flex; }
        @keyframes fadeInLightbox { from { opacity: 0; } to { opacity: 1; } }
        .midia-lightbox__close {
            position: absolute; top: 20px; right: 24px;
            width: 44px; height: 44px; border-radius: 50%;
            background: rgba(255,255,255,.12); border: 1.5px solid rgba(255,255,255,.25);
            color: #fff; font-size: 1.3rem; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: background .18s;
        }
        .midia-lightbox__close:hover { background: rgba(255,255,255,.22); }
        .midia-lightbox__content {
            max-width: 90vw; max-height: 88vh;
            display: flex; align-items: center; justify-content: center;
        }
        .midia-lightbox__content img,
        .midia-lightbox__content video {
            max-width: 90vw; max-height: 88vh;
            border-radius: 12px;
            box-shadow: 0 24px 80px rgba(0,0,0,.6);
        }
        </style>

        <script>
        // ── Tipo de mídia: seleção visual ────────────────────────────────────
        function selecionarTipoMidia(tipo) {
            document.querySelectorAll('.midia-tipo-btn').forEach(function(btn) {
                btn.classList.toggle('active', btn.dataset.tipo === tipo);
            });
            document.getElementById('midiaHiddenTipo').value = tipo;

            var input = document.getElementById('arquivoMidia');
            var hint  = document.getElementById('midiaDropHint');
            var map = {
                imagem: { accept: 'image/*',                            hint: 'Imagens: JPG, PNG, WebP, GIF — até 20MB' },
                video:  { accept: 'video/*',                            hint: 'Vídeos: MP4, WebM, MOV — até 50MB' },
                audio:  { accept: 'audio/*,.mp3,.ogg,.aac,.m4a,.wav',   hint: 'Áudios: MP3, OGG, AAC, M4A, WAV — até 20MB' },
            };
            if (map[tipo]) {
                input.accept = map[tipo].accept;
                if (hint) hint.textContent = map[tipo].hint;
            }
            // Remove seleção atual ao trocar tipo
            removerArquivoSelecionado();
        }

        // ── Preview do arquivo ───────────────────────────────────────────────
        document.getElementById('arquivoMidia').addEventListener('change', function() {
            var file = this.files[0];
            if (file) mostrarPreview(file);
        });

        function mostrarPreview(file) {
            var area  = document.getElementById('midiaPreviewArea');
            var media = document.getElementById('midiaPreviewMedia');
            var info  = document.getElementById('midiaPreviewInfo');
            var wrap  = document.getElementById('midiaDropZone').parentElement; // .midia-dropzone-wrap

            media.innerHTML = '';
            var url  = URL.createObjectURL(file);
            var tipo = file.type.split('/')[0];

            // Garante aspect-ratio:1 igual aos cards da galeria
            media.style.cssText = 'aspect-ratio:1;overflow:hidden;background:#0a0f12;display:flex;align-items:center;justify-content:center;';

            if (tipo === 'image') {
                var img = document.createElement('img');
                img.src = url; img.alt = file.name;
                img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
                media.appendChild(img);
            } else if (tipo === 'video') {
                var vid = document.createElement('video');
                vid.src = url; vid.muted = true; vid.autoplay = true; vid.loop = true;
                vid.style.cssText = 'width:100%;height:100%;object-fit:cover;';
                media.appendChild(vid);
            } else {
                // Áudio: visual de barras igual à galeria
                var ph = document.createElement('div');
                ph.className = 'midia-audio-visual';
                ph.style.width = '100%';
                var bars = '<div class="midia-audio-bars">';
                for (var b = 0; b < 8; b++) bars += '<span class="midia-audio-bar" style="animation-delay:' + (b*0.07) + 's"></span>';
                bars += '</div>';
                var aud = document.createElement('audio');
                aud.src = url; aud.controls = true;
                aud.style.cssText = 'width:100%;margin-top:8px;';
                ph.innerHTML = bars + '<i class="ph ph-waveform midia-audio-icon"></i>';
                ph.appendChild(aud);
                media.appendChild(ph);
            }

            // Nome truncado + tamanho
            var nome = file.name.length > 22 ? file.name.substring(0, 19) + '...' : file.name;
            info.textContent = nome + ' — ' + bytesLeg(file.size);

            area.style.display = 'block';
            // Anima entrada do card
            var card = document.getElementById('midiaPreviewCard');
            card.style.animation = 'none';
            card.offsetHeight; // reflow
            card.style.animation = '';
        }

        function removerArquivoSelecionado() {
            var input = document.getElementById('arquivoMidia');
            input.value = '';
            document.getElementById('midiaPreviewArea').style.display = 'none';
            document.getElementById('midiaDropZone').style.display = '';
            document.getElementById('midiaPreviewMedia').innerHTML = '';
        }

        function bytesLeg(n) {
            if (n >= 1048576) return (n/1048576).toFixed(1) + ' MB';
            if (n >= 1024)    return (n/1024).toFixed(0) + ' KB';
            return n + ' B';
        }

        // ── Drag & Drop ──────────────────────────────────────────────────────
        (function() {
            var dz    = document.getElementById('midiaDropZone');
            var input = document.getElementById('arquivoMidia');
            if (!dz || !input) return;

            ['dragenter','dragover'].forEach(function(ev) {
                dz.addEventListener(ev, function(e) { e.preventDefault(); dz.classList.add('dragover'); });
            });
            ['dragleave','dragend','drop'].forEach(function(ev) {
                dz.addEventListener(ev, function(e) { e.preventDefault(); dz.classList.remove('dragover'); });
            });
            dz.addEventListener('drop', function(e) {
                var file = e.dataTransfer.files[0];
                if (file) {
                    // Simula a seleção via input
                    var dt = new DataTransfer();
                    dt.items.add(file);
                    input.files = dt.files;
                    mostrarPreview(file);
                }
            });
        })();

        // ── Confirm delete com card premium ──────────────────────────────────
        function confirmDel(e, form) {
            e.preventDefault();
            var card = form.closest('.midia-card-premium');
            if (!confirm('Remover esta mídia permanentemente?')) return false;
            if (card) {
                card.style.transition = 'opacity .3s, transform .3s';
                card.style.opacity = '0';
                card.style.transform = 'scale(.85)';
                setTimeout(function() { form.submit(); }, 280);
            } else {
                form.submit();
            }
            return false;
        }

        // ── Lightbox ─────────────────────────────────────────────────────────
        document.querySelectorAll('.midia-overlay-btn--view').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var src  = btn.getAttribute('href');
                var ext  = src.split('.').pop().toLowerCase();
                var lb   = document.getElementById('midiaLightbox');
                var img  = document.getElementById('midiaLightboxImg');
                var vid  = document.getElementById('midiaLightboxVid');
                var isVid = ['mp4','webm','mov','avi'].indexOf(ext) > -1;
                img.style.display = isVid ? 'none' : 'block';
                vid.style.display = isVid ? 'block' : 'none';
                if (isVid) { vid.src = src; vid.load(); } else { img.src = src; }
                lb.classList.add('open');
            });
        });

        function fecharLightbox() {
            var lb  = document.getElementById('midiaLightbox');
            var vid = document.getElementById('midiaLightboxVid');
            lb.classList.remove('open');
            vid.pause(); vid.src = '';
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') fecharLightbox();
        });

        // ── Aviso free (para botões de tipo bloqueado) ────────────────────────
        function mostrarAvisoFree(msg) {
            var div  = document.getElementById('midiaAvisoFree');
            var span = div ? div.querySelector('span') : null;
            if (!div || !span) return;
            span.innerHTML = msg;
            div.style.display = 'flex';
            clearTimeout(div._t);
            div._t = setTimeout(function() { div.style.display = 'none'; }, 4000);
        }
        </script>

        <?php endif; ?>

        <!-- ════════════════════════════════════════════════════════════════════
             ABA: GANHAR DIAS (GROQ KEY)
        ═══════════════════════════════════════════════════════════════════════ -->
        <?php if ($aba === 'groq_key'): ?>
        <div class="dash-section">

            <div class="dash-section__header">
                <h1 class="dash-section__title">
                    <i class="ph ph-gift"></i>
                    Ganhar Dias de Uso Grátis
                </h1>
                <p class="dash-section__sub">Insira uma chave de API do Groq válida com <strong>No Expiration</strong> e ganhe <strong>+<?= $bonusDiasChave ?> dias</strong> de uso gratuito.</p>
            </div>

            <!-- Banner de dias atuais -->
            <div class="gk-status-banner">
                <div class="gk-status-banner__left">
                    <div class="gk-status-banner__icon"><i class="ph ph-calendar-check"></i></div>
                    <div class="gk-status-banner__info">
                        <span class="gk-status-banner__label">Seu plano atual</span>
                        <strong class="gk-status-banner__plan <?= $sidebarPlanoClass ?>"><?= e($sidebarPlanoLabel) ?></strong>
                        <?php if ($sidebarPlanoExpira): ?>
                        <span class="gk-status-banner__expira"><?= e($sidebarPlanoExpira) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="gk-status-banner__right">
                    <?php if (!empty($groqKeysUsuario)): ?>
                    <div class="gk-keys-count">
                        <i class="ph ph-key"></i>
                        <span><?= count($groqKeysUsuario) ?> chave<?= count($groqKeysUsuario) > 1 ? 's' : '' ?> cadastrada<?= count($groqKeysUsuario) > 1 ? 's' : '' ?></span>
                        <strong>+<?= $totalDiasGanhos ?> dias ganhos</strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Como gerar a chave — passo a passo -->
            <div class="dash-card gk-howto-card">
                <div class="dash-card__header">
                    <i class="ph ph-list-numbers"></i>
                    <h2>Como gerar sua chave Groq</h2>
                    <a href="https://console.groq.com/keys" target="_blank" rel="noopener noreferrer" class="gk-groq-link-btn">
                        <i class="ph ph-arrow-square-out"></i>
                        Abrir console.groq.com
                    </a>
                </div>

                <div class="gk-steps">
                    <div class="gk-step">
                        <div class="gk-step__num">1</div>
                        <div class="gk-step__body">
                            <div class="gk-step__icon"><i class="ph ph-user-circle-plus"></i></div>
                            <div class="gk-step__text">
                                <strong>Crie uma conta gratuita no Groq</strong>
                                <span>Acesse <a href="https://console.groq.com" target="_blank" rel="noopener noreferrer" class="gk-inline-link">console.groq.com</a> e cadastre-se. É totalmente grátis.</span>
                            </div>
                        </div>
                    </div>

                    <div class="gk-step-arrow"><i class="ph ph-arrow-right"></i></div>

                    <div class="gk-step">
                        <div class="gk-step__num">2</div>
                        <div class="gk-step__body">
                            <div class="gk-step__icon"><i class="ph ph-key"></i></div>
                            <div class="gk-step__text">
                                <strong>Vá em API Keys</strong>
                                <span>No menu lateral clique em <em>API Keys</em> ou acesse diretamente <a href="https://console.groq.com/keys" target="_blank" rel="noopener noreferrer" class="gk-inline-link">console.groq.com/keys</a></span>
                            </div>
                        </div>
                    </div>

                    <div class="gk-step-arrow"><i class="ph ph-arrow-right"></i></div>

                    <div class="gk-step">
                        <div class="gk-step__num">3</div>
                        <div class="gk-step__body">
                            <div class="gk-step__icon"><i class="ph ph-plus-circle"></i></div>
                            <div class="gk-step__text">
                                <strong>Clique em "Create API Key"</strong>
                                <span>Dê um nome para a chave e em <em>Expiration</em> selecione obrigatoriamente <strong>No Expiration</strong>.</span>
                            </div>
                        </div>
                    </div>

                    <div class="gk-step-arrow"><i class="ph ph-arrow-right"></i></div>

                    <div class="gk-step">
                        <div class="gk-step__num">4</div>
                        <div class="gk-step__body">
                            <div class="gk-step__icon"><i class="ph ph-copy"></i></div>
                            <div class="gk-step__text">
                                <strong>Copie a chave gerada</strong>
                                <span>Ela começa com <code>gsk_</code>. Copie agora — ela só aparece uma vez!</span>
                            </div>
                        </div>
                    </div>

                    <div class="gk-step-arrow"><i class="ph ph-arrow-right"></i></div>

                    <div class="gk-step gk-step--success">
                        <div class="gk-step__num gk-step__num--done"><i class="ph ph-check"></i></div>
                        <div class="gk-step__body">
                            <div class="gk-step__icon"><i class="ph ph-rocket-launch"></i></div>
                            <div class="gk-step__text">
                                <strong>Cole aqui e ganhe +<?= $bonusDiasChave ?> dias!</strong>
                                <span>Cole a chave no campo abaixo e clique em <strong>Validar e Ganhar</strong>.</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="gk-howto-tip">
                    <i class="ph ph-sparkle"></i>
                    <span><strong>Como isso melhora sua IA?</strong> Ao integrar sua chave, você injeta poder de processamento direto no seu bot. Isso permite que a IA use mais recursos de forma inteligente no seu painel — resultando em respostas mais precisas, coerentes e com maior qualidade para você. É uma potencialização real na inteligência das suas conversas, e você ainda ganha bônus de dias para usar!</span>
                </div>
            </div>

            <!-- Formulário de inserção da chave -->
            <div class="dash-card gk-input-card">
                <div class="dash-card__header">
                    <i class="ph ph-key-return"></i>
                    <h2>Inserir Chave Groq</h2>
                    <span class="gk-reward-badge"><i class="ph ph-gift"></i> +<?= $bonusDiasChave ?> dias grátis</span>
                </div>

                <div class="gk-input-area">
                    <div class="gk-input-wrap">
                        <div class="gk-key-prefix">gsk_</div>
                        <input type="text" id="groqKeyInput" class="gk-key-input"
                               placeholder="Cole aqui sua chave de API do Groq..."
                               autocomplete="off" spellcheck="false">
                        <button type="button" class="gk-paste-btn" onclick="colarChave()" title="Colar da área de transferência">
                            <i class="ph ph-clipboard-text"></i>
                        </button>
                    </div>
                    <div class="gk-input-hint" style="margin-top:6px;color:#92400e;background:#fef3c7;border:1px solid #d97706;border-radius:7px;padding:7px 12px;font-size:.79rem;display:flex;gap:8px;align-items:flex-start;">
                        <i class="ph ph-info" style="flex-shrink:0;margin-top:1px;"></i>
                        <span>Cole sua chave normalmente — com ou sem o <code style="background:#fde68a;padding:1px 5px;border-radius:4px;">gsk_</code>. O prefixo verde é apenas uma referência visual, não precisa removê-lo da chave.</span>
                    </div>
                    <div class="gk-input-hint">
                        <i class="ph ph-shield-check"></i>
                        A chave é validada em tempo real antes de ser aceita. Apenas chaves válidas são registradas.
                    </div>
                    <div class="gk-feedback" id="gkFeedback" style="display:none;"></div>
                </div>

                <div class="gk-submit-row">
                    <button type="button" class="btn-dash btn-dash--primary btn-dash--lg gk-submit-btn" id="gkSubmitBtn" onclick="enviarGroqKey()">
                        <i class="ph ph-rocket-launch"></i>
                        Validar e Ganhar +<?= $bonusDiasChave ?> Dias
                    </button>
                </div>
            </div>

            <!-- Histórico de chaves inseridas -->
            <?php if (!empty($groqKeysUsuario)): ?>
            <div class="dash-card">
                <div class="dash-card__header">
                    <i class="ph ph-clock-counter-clockwise"></i>
                    <h2>Minhas Chaves Cadastradas</h2>
                    <span class="dash-card__count"><?= count($groqKeysUsuario) ?></span>
                </div>

                <div class="gk-history-list">
                    <?php foreach ($groqKeysUsuario as $gk): ?>
                    <div class="gk-history-item">
                        <div class="gk-history-item__icon"><i class="ph ph-check-circle"></i></div>
                        <div class="gk-history-item__info">
                            <span class="gk-history-item__key"><i class="ph ph-key"></i> <?= e($gk['key_masked']) ?></span>
                            <span class="gk-history-item__date"><i class="ph ph-calendar-blank"></i> <?= date('d/m/Y H:i', strtotime($gk['criado_em'])) ?></span>
                        </div>
                        <div class="gk-history-item__reward">
                            <span class="gk-reward-pill">+<?= (int)$gk['dias_ganhos'] ?> dias</span>
                            <span class="gk-reward-note">Chave ativa no sistema</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /.dash-section -->

        <!-- Modal: Iframe do console Groq -->
        <div class="gk-modal" id="gkGroqModal">
            <div class="gk-modal__overlay" onclick="fecharModalGroq()"></div>
            <div class="gk-modal__box">
                <div class="gk-modal__header">
                    <div class="gk-modal__title">
                        <img src="https://groq.com/favicon.ico" alt="Groq" class="gk-modal__favicon" onerror="this.style.display='none'">
                        console.groq.com
                    </div>
                    <div class="gk-modal__actions">
                        <a href="https://console.groq.com/keys" target="_blank" rel="noopener noreferrer" class="gk-modal__external-btn" title="Abrir em nova aba">
                            <i class="ph ph-arrow-square-out"></i>
                        </a>
                        <button type="button" class="gk-modal__close-btn" onclick="fecharModalGroq()">
                            <i class="ph ph-x"></i>
                        </button>
                    </div>
                </div>
                <div class="gk-modal__iframe-wrap">
                    <iframe id="gkGroqIframe" src="" sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox"
                            referrerpolicy="no-referrer-when-downgrade"
                            title="Groq Console"></iframe>
                    <div class="gk-modal__iframe-fallback" id="gkIframeFallback" style="display:none;">
                        <div class="gk-fallback-inner">
                            <i class="ph ph-warning-circle"></i>
                            <p>O site do Groq bloqueou a exibição em iframe (política de segurança).</p>
                            <a href="https://console.groq.com/keys" target="_blank" rel="noopener noreferrer" class="btn-dash btn-dash--primary">
                                <i class="ph ph-arrow-square-out"></i>
                                Abrir console.groq.com em nova aba
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <style>
        /* ════════════════════════════════════════════════════════════════════
           ABA GANHAR DIAS — GROQ KEY
        ════════════════════════════════════════════════════════════════════ */

        /* Nav item especial — estilos globais em .dash-nav__item--groq e .dash-sidebar__extra */

        /* Banner de status */
        .gk-status-banner {
            display: flex; align-items: center; justify-content: space-between;
            background: linear-gradient(135deg, #0f1f30 0%, #0a2540 100%);
            border: 1px solid #1e3a5f; border-radius: var(--dash-radius);
            padding: 20px 28px; margin-bottom: 20px; gap: 20px;
            animation: fadeUp .4s ease both;
        }
        .gk-status-banner__left { display: flex; align-items: center; gap: 16px; }
        .gk-status-banner__icon {
            width: 52px; height: 52px; border-radius: 14px;
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 1.5rem; flex-shrink: 0;
        }
        .gk-status-banner__label { font-size: .72rem; color: #64748b; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; display: block; }
        .gk-status-banner__plan { display: block; font-size: 1.1rem; font-weight: 800; color: #e2e8f0; }
        .gk-status-banner__plan.trial    { color: #fbbf24; }
        .gk-status-banner__plan.pago     { color: #34d399; }
        .gk-status-banner__plan.expirado { color: #f87171; }
        .gk-status-banner__expira { display: block; font-size: .78rem; color: #94a3b8; margin-top: 3px; }
        .gk-keys-count {
            display: flex; flex-direction: column; align-items: flex-end; gap: 3px;
            font-size: .8rem; color: #94a3b8;
        }
        .gk-keys-count i { color: #fbbf24; }
        .gk-keys-count strong { color: #34d399; font-size: 1rem; }

        /* Passo a passo */
        .gk-howto-card { border-top: 3px solid var(--dash-accent); }
        .gk-groq-link-btn {
            display: inline-flex; align-items: center; gap: 6px;
            background: linear-gradient(135deg, var(--dash-accent), var(--dash-accent-dk));
            color: #fff; font-size: .78rem; font-weight: 700;
            padding: 7px 16px; border-radius: 8px; border: none;
            cursor: pointer; margin-left: auto;
            transition: filter .18s, transform .12s;
            box-shadow: 0 2px 8px rgba(0,192,96,.35);
        }
        .gk-groq-link-btn:hover { filter: brightness(1.1); transform: translateY(-1px); }

        .gk-steps {
            display: flex; align-items: center; gap: 0; flex-wrap: wrap; row-gap: 12px;
            margin-bottom: 18px;
        }
        .gk-step {
            flex: 1; min-width: 140px;
            background: var(--dash-bg); border: 1px solid var(--dash-border);
            border-radius: 12px; padding: 16px 14px;
            display: flex; flex-direction: column; gap: 10px;
            transition: box-shadow .2s, border-color .2s;
        }
        .gk-step:hover { border-color: var(--dash-accent); box-shadow: 0 0 0 3px var(--dash-accent-dim); }
        .gk-step--success { border-color: rgba(34,197,94,.4); background: rgba(34,197,94,.07); }
        .gk-step__num {
            width: 26px; height: 26px; border-radius: 50%;
            background: var(--dash-accent); color: #fff;
            font-size: .75rem; font-weight: 800;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .gk-step__num--done { background: #22c55e; }
        .gk-step__body { display: flex; flex-direction: column; gap: 8px; }
        .gk-step__icon { font-size: 1.5rem; color: var(--dash-accent); }
        .gk-step--success .gk-step__icon { color: #16a34a; }
        .gk-step__text { display: flex; flex-direction: column; gap: 4px; }
        .gk-step__text strong { font-size: .82rem; font-weight: 700; color: var(--dash-text); line-height: 1.3; }
        .gk-step__text span { font-size: .75rem; color: var(--dash-muted); line-height: 1.5; }
        .gk-step__text code {
            background: rgba(99,102,241,.12); color: #6366f1;
            padding: 1px 5px; border-radius: 4px; font-size: .78rem;
        }
        .gk-step-arrow {
            color: var(--dash-border); font-size: 1.1rem;
            flex-shrink: 0; padding: 0 6px; align-self: center;
        }
        .gk-inline-link {
            background: none; border: none; padding: 0;
            color: var(--dash-accent-dk); font-weight: 700; font-size: inherit;
            cursor: pointer; text-decoration: underline;
            font-family: inherit;
        }
        .gk-inline-link:hover { color: var(--dash-accent); }
        .gk-howto-tip {
            display: flex; align-items: flex-start; gap: 10px;
            background: var(--dash-accent-dim); border: 1px solid rgba(0,192,96,.25);
            border-radius: 10px; padding: 12px 16px;
            font-size: .8rem; color: var(--dash-text); line-height: 1.5;
        }
        .gk-howto-tip i { color: var(--dash-accent); font-size: 1rem; flex-shrink: 0; margin-top: 1px; }

        /* Input card */
        .gk-input-card { border-top: 3px solid var(--dash-accent); }
        .gk-reward-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: linear-gradient(135deg, rgba(34,197,94,.15), rgba(16,185,129,.1));
            color: #16a34a; border: 1px solid rgba(34,197,94,.3);
            font-size: .75rem; font-weight: 700; padding: 4px 12px;
            border-radius: 20px; margin-left: auto;
        }
        .gk-input-area { display: flex; flex-direction: column; gap: 10px; margin-bottom: 20px; }
        .gk-input-wrap {
            display: flex; align-items: stretch; gap: 0;
            border: 1.5px solid var(--dash-border); border-radius: 10px;
            overflow: hidden; background: var(--dash-bg);
            transition: border-color .2s, box-shadow .2s;
        }
        .gk-input-wrap:focus-within {
            border-color: var(--dash-accent);
            box-shadow: 0 0 0 3px rgba(0,192,96,.12);
        }
        .gk-key-prefix {
            padding: 12px 14px; background: var(--dash-accent-dim);
            color: var(--dash-accent-dk); font-weight: 800; font-size: .875rem;
            font-family: monospace; border-right: 1px solid var(--dash-border);
            display: flex; align-items: center;
        }
        .gk-key-input {
            flex: 1; padding: 12px 14px; border: none; outline: none;
            font-size: .875rem; color: var(--dash-text); background: transparent;
            font-family: monospace; min-width: 0;
        }
        .gk-paste-btn {
            padding: 0 16px; background: none; border: none;
            border-left: 1px solid var(--dash-border);
            color: var(--dash-muted); font-size: 1.1rem;
            cursor: pointer; transition: background .15s, color .15s;
            display: flex; align-items: center;
        }
        .gk-paste-btn:hover { background: var(--dash-accent-dim); color: var(--dash-accent-dk); }
        .gk-input-hint {
            display: flex; align-items: center; gap: 7px;
            font-size: .78rem; color: var(--dash-muted); line-height: 1.5;
        }
        .gk-input-hint i { color: var(--dash-accent); font-size: .9rem; flex-shrink: 0; }
        .gk-feedback {
            padding: 12px 16px; border-radius: 10px; font-size: .85rem;
            font-weight: 600; display: flex; align-items: center; gap: 9px;
            animation: fadeUp .3s ease both;
        }
        .gk-feedback--success {
            background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.3);
            color: #15803d;
        }
        .gk-feedback--error {
            background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.25);
            color: #b91c1c;
        }
        .gk-feedback--loading {
            background: rgba(99,102,241,.08); border: 1px solid rgba(99,102,241,.2);
            color: var(--dash-text);
        }
        .gk-submit-row { display: flex; justify-content: flex-end; border-top: 1px solid var(--dash-border); padding-top: 16px; }
        .gk-submit-btn { min-width: 220px; justify-content: center; }

        /* Histórico */
        .gk-history-list { display: flex; flex-direction: column; gap: 10px; }
        .gk-history-item {
            display: flex; align-items: center; gap: 14px;
            background: var(--dash-bg); border: 1px solid var(--dash-border);
            border-radius: 10px; padding: 14px 18px;
            transition: border-color .2s;
        }
        .gk-history-item:hover { border-color: var(--dash-accent); }
        .gk-history-item__icon {
            width: 36px; height: 36px; border-radius: 50%;
            background: rgba(34,197,94,.12); color: #22c55e;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; flex-shrink: 0;
        }
        .gk-history-item__info { flex: 1; display: flex; flex-direction: column; gap: 3px; }
        .gk-history-item__key {
            display: flex; align-items: center; gap: 5px;
            font-family: monospace; font-size: .82rem; font-weight: 600;
            color: var(--dash-text);
        }
        .gk-history-item__key i { color: var(--dash-accent); }
        .gk-history-item__date {
            display: flex; align-items: center; gap: 5px;
            font-size: .72rem; color: var(--dash-muted);
        }
        .gk-history-item__reward { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; }
        .gk-reward-pill {
            background: rgba(34,197,94,.12); color: #16a34a;
            border: 1px solid rgba(34,197,94,.3);
            font-size: .72rem; font-weight: 800; padding: 3px 10px;
            border-radius: 20px;
        }

        .gk-reward-note { font-size: .7rem; color: var(--dash-muted); }

        /* Modal Groq */
        .gk-modal {
            display: none; position: fixed; inset: 0; z-index: 9000;
            align-items: center; justify-content: center;
        }
        .gk-modal.open { display: flex; animation: fadeInLightbox .22s ease; }
        .gk-modal__overlay {
            position: absolute; inset: 0;
            background: rgba(0,0,0,.75); backdrop-filter: blur(6px);
        }
        .gk-modal__box {
            position: relative; z-index: 1;
            width: min(96vw, 1100px); height: min(88vh, 780px);
            border-radius: 18px; overflow: hidden;
            background: var(--dash-surface);
            border: 1px solid var(--dash-border);
            box-shadow: 0 24px 80px rgba(0,0,0,.5);
            display: flex; flex-direction: column;
            animation: gkModalIn .3s cubic-bezier(.34,1.56,.64,1);
        }
        @keyframes gkModalIn {
            from { transform: scale(.92) translateY(20px); opacity: 0; }
            to   { transform: scale(1) translateY(0); opacity: 1; }
        }
        .gk-modal__header {
            display: flex; align-items: center;
            padding: 14px 20px; border-bottom: 1px solid var(--dash-border);
            background: var(--dash-bg); gap: 10px; flex-shrink: 0;
        }
        .gk-modal__title {
            display: flex; align-items: center; gap: 8px;
            font-weight: 700; font-size: .9rem; color: var(--dash-text); flex: 1;
        }
        .gk-modal__favicon { width: 18px; height: 18px; border-radius: 4px; }
        .gk-modal__actions { display: flex; align-items: center; gap: 8px; }
        .gk-modal__external-btn, .gk-modal__close-btn {
            width: 34px; height: 34px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; border: 1px solid var(--dash-border);
            background: none; cursor: pointer; color: var(--dash-muted);
            text-decoration: none; transition: background .15s, color .15s;
        }
        .gk-modal__external-btn:hover { background: var(--dash-accent-dim); color: var(--dash-accent-dk); }
        .gk-modal__close-btn:hover { background: rgba(239,68,68,.1); color: #dc2626; border-color: rgba(239,68,68,.3); }
        .gk-modal__iframe-wrap { flex: 1; position: relative; overflow: hidden; }
        .gk-modal__iframe-wrap iframe {
            width: 100%; height: 100%; border: none; display: block;
        }
        .gk-modal__iframe-fallback {
            position: absolute; inset: 0; background: var(--dash-bg);
            display: flex; align-items: center; justify-content: center;
        }
        .gk-fallback-inner {
            text-align: center; display: flex; flex-direction: column;
            align-items: center; gap: 16px; padding: 40px;
        }
        .gk-fallback-inner i { font-size: 3rem; color: var(--dash-accent); }
        .gk-fallback-inner p { color: var(--dash-muted); font-size: .9rem; }

        @media (max-width: 768px) {
            .gk-steps { flex-direction: column; align-items: stretch; }
            .gk-step-arrow { transform: rotate(90deg); align-self: center; padding: 0; }
            .gk-status-banner { flex-wrap: wrap; }
            .gk-modal__box { width: 100vw; height: 100dvh; border-radius: 0; }
        }
        </style>

        <script>
        // ── Enviar Chave Groq ────────────────────────────────────────────────
        async function enviarGroqKey() {
            var input = document.getElementById('groqKeyInput');
            var raw   = (input ? input.value.trim() : '');
            // Aceitar com ou sem prefixo "gsk_"
            var apiKey = raw.startsWith('gsk_') ? raw : ('gsk_' + raw);
            if (apiKey.length < 15) {
                setGkFeedback('error', '<i class="ph ph-warning-circle"></i> Cole uma chave válida antes de continuar.');
                return;
            }
            var btn = document.getElementById('gkSubmitBtn');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="ph ph-spinner" style="animation:spin .7s linear infinite;display:inline-block"></i> Validando...'; }
            setGkFeedback('loading', '<div class="ms-qr-spinner" style="width:16px;height:16px;border-width:2px;flex-shrink:0;"></div> Validando chave com o servidor do Groq...');

            try {
                var res  = await fetch('dashboard.php?ajax=enviar_groq_key', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ api_key: apiKey }),
                });
                var data = await res.json();
                if (data.ok) {
                    var dias = data.dias_ganhos || <?= $bonusDiasChave ?>;
                    setGkFeedback('success', '<i class="ph ph-check-circle"></i> Chave válida cadastrada! <strong>+' + dias + ' dias</strong> adicionados ao seu plano. Recarregando...');
                    setTimeout(function() { window.location.reload(); }, 2200);
                } else {
                    setGkFeedback('error', '<i class="ph ph-x-circle"></i> ' + (data.erro || 'Erro desconhecido.'));
                    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="ph ph-rocket-launch"></i> Validar e Ganhar +<?= $bonusDiasChave ?> Dias'; }
                }
            } catch(e) {
                setGkFeedback('error', '<i class="ph ph-x-circle"></i> Erro de conexão: ' + e.message);
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="ph ph-rocket-launch"></i> Validar e Ganhar +<?= $bonusDiasChave ?> Dias'; }
            }
        }

        function setGkFeedback(type, html) {
            var div = document.getElementById('gkFeedback');
            if (!div) return;
            div.className = 'gk-feedback gk-feedback--' + type;
            div.innerHTML = html;
            div.style.display = 'flex';
        }

        async function colarChave() {
            try {
                var text = await navigator.clipboard.readText();
                var input = document.getElementById('groqKeyInput');
                if (input) {
                    // Remove prefixo "gsk_" se o usuário colou com ele
                    input.value = text.replace(/^gsk_/, '').trim();
                }
            } catch(e) {
                alert('Não foi possível acessar a área de transferência. Cole manualmente no campo.');
            }
        }

        // ── Modal Groq (iframe) ──────────────────────────────────────────────
        function abrirModalGroq(url) {
            var targetUrl = url || 'https://console.groq.com/keys';
            var modal  = document.getElementById('gkGroqModal');
            var iframe = document.getElementById('gkGroqIframe');
            var fallback = document.getElementById('gkIframeFallback');
            if (!modal || !iframe) return;
            iframe.src = targetUrl;
            fallback.style.display = 'none';
            modal.classList.add('open');
            document.body.style.overflow = 'hidden';

            // Detecta se o iframe foi bloqueado (X-Frame-Options)
            iframe.onload = function() {
                try {
                    // Se conseguir acessar contentWindow.location, o iframe carregou
                    var loc = iframe.contentWindow.location.href;
                    // Se for about:blank ou vazio, provavelmente foi bloqueado
                    if (!loc || loc === 'about:blank') {
                        fallback.style.display = 'flex';
                    }
                } catch(e) {
                    // Cross-origin: o site carregou (normal) — não mostrar fallback
                }
            };

            // Timeout de segurança: se demorar muito, mostra fallback
            var _t = setTimeout(function() {
                try {
                    if (iframe.contentWindow.location.href === 'about:blank') {
                        fallback.style.display = 'flex';
                    }
                } catch(e) { /* ok, cross-origin normal */ }
            }, 6000);
            iframe._safetyTimeout = _t;
        }

        function fecharModalGroq() {
            var modal  = document.getElementById('gkGroqModal');
            var iframe = document.getElementById('gkGroqIframe');
            if (!modal) return;
            modal.classList.remove('open');
            document.body.style.overflow = '';
            if (iframe) { clearTimeout(iframe._safetyTimeout); iframe.src = ''; }
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') fecharModalGroq();
        });

        // Enter no campo de chave dispara envio
        (function() {
            var inp = document.getElementById('groqKeyInput');
            if (inp) inp.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') enviarGroqKey();
            });
        })();
        </script>

        <?php endif; ?>


        <!-- ════════════════════════════════════════════════════════════════════
             ABA: AUTO RESPOSTAS
        ═══════════════════════════════════════════════════════════════════════ --><?php if ($aba === 'auto_respostas'): ?>
        <div class="dash-section ar-section">

            <div class="dash-section__header">
                <h1 class="dash-section__title">
                    <i class="ph ph-robot"></i>
                    Auto Respostas
                </h1>
                <p class="dash-section__sub">Cadastre respostas automáticas com gatilhos diretos, delay humanizado e mensagens interativas completas.</p>
            </div>

            <?php if ($salvoMsg): ?><div class="dash-alert dash-alert--success"><i class="ph ph-check-circle"></i> <?= e($salvoMsg) ?></div><?php endif; ?>
            <?php if (!empty($salvoErro)): ?><div class="dash-alert dash-alert--error"><i class="ph ph-warning"></i> <?= e($salvoErro) ?></div><?php endif; ?>

            <?php if ($isFreePlan): ?>
            <?php $arUsadas = count($autoRespostas); $arMax = 5; ?>
            <div class="dash-alert dash-alert--info" style="display:flex;align-items:center;gap:12px;justify-content:space-between;flex-wrap:wrap;">
                <span><i class="ph ph-info"></i> <strong>Plano Gratuito:</strong> Você está usando <strong><?= $arUsadas ?>/<?= $arMax ?></strong> auto respostas. Faça upgrade para criar respostas ilimitadas.</span>
                <div style="display:flex;align-items:center;gap:8px;">
                    <div style="background:var(--dash-border,#e2e8f0);border-radius:99px;height:8px;width:120px;overflow:hidden;">
                        <div style="background:<?= $arUsadas >= $arMax ? '#ef4444' : '#f59e0b' ?>;height:100%;width:<?= min(100, round($arUsadas/$arMax*100)) ?>%;border-radius:99px;transition:.3s"></div>
                    </div>
                    <a href="?aba=config" class="btn-dash btn-dash--primary" style="padding:4px 14px;font-size:.8rem;">Fazer Upgrade</a>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Card: Cadastrar Auto Resposta ── -->
            <div class="dash-card ar-form-card">
                <div class="dash-card__header">
                    <i class="ph ph-plus-circle"></i>
                    <h2>Nova Auto Resposta</h2>
                </div>

                <form method="POST" class="dash-form ar-form" id="arMainForm">
                    <input type="hidden" name="acao" value="add_auto_resposta">
                    <input type="hidden" name="ar_payload" id="arPayloadHidden" value="{}">
                    <input type="hidden" name="ar_tipo_mensagem" id="arTipoHidden" value="texto">

                    <!-- Linha 1: Nome + Gatilhos -->
                    <div class="ar-grid-2">
                        <div class="dash-field">
                            <label><i class="ph ph-tag"></i> Nome da regra</label>
                            <input type="text" name="ar_nome" class="dash-input" placeholder="Ex: Menu principal" required maxlength="120">
                        </div>
                        <div class="dash-field">
                            <label><i class="ph ph-lightning"></i> Gatilhos <small>(separados por vírgula)</small></label>
                            <input type="text" name="ar_gatilhos" class="dash-input" placeholder="oi, olá, menu, início" required>
                            <small class="dash-field__hint">Qualquer uma dessas palavras ativa esta resposta.</small>
                        </div>
                    </div>

                    <!-- Linha 2: Delay humanizado -->
                    <div class="ar-grid-3 ar-delay-row">
                        <div class="dash-field">
                            <label><i class="ph ph-clock"></i> Delay mínimo (seg)</label>
                            <input type="number" name="ar_delay_min" class="dash-input" value="2" min="0" max="60">
                        </div>
                        <div class="dash-field">
                            <label><i class="ph ph-clock-countdown"></i> Delay máximo (seg)</label>
                            <input type="number" name="ar_delay_max" class="dash-input" value="6" min="0" max="120">
                        </div>
                        <div class="dash-field">
                            <label><i class="ph ph-list-numbers"></i> Ordem de prioridade</label>
                            <input type="number" name="ar_ordem" class="dash-input" value="0" min="0" max="999">
                            <small class="dash-field__hint">Menor número = maior prioridade.</small>
                        </div>
                    </div>

                    <!-- Linha 3: Tipo de mensagem -->
                    <div class="dash-field">
                        <label><i class="ph ph-chat-circle-dots"></i> Tipo de mensagem interativa</label>
                        <div class="ar-tipo-grid" id="arTipoGrid">
                            <button type="button" class="ar-tipo-btn active" data-tipo="texto" onclick="arSelecionarTipo('texto')">
                                <i class="ph ph-chat-text"></i><span>Texto</span>
                            </button>
                            <button type="button" class="ar-tipo-btn" data-tipo="reply_buttons" onclick="arSelecionarTipo('reply_buttons')">
                                <i class="ph ph-squares-four"></i><span>Botões de Resposta</span>
                            </button>
                            <button type="button" class="ar-tipo-btn" data-tipo="list_message" onclick="arSelecionarTipo('list_message')">
                                <i class="ph ph-list-bullets"></i><span>Mensagem em Lista</span>
                            </button>
                            <button type="button" class="ar-tipo-btn" data-tipo="cta_button" onclick="arSelecionarTipo('cta_button')">
                                <i class="ph ph-link"></i><span>Botão de Ação</span>
                            </button>
                            <button type="button" class="ar-tipo-btn" data-tipo="poll" onclick="arSelecionarTipo('poll')">
                                <i class="ph ph-chart-bar"></i><span>Enquete</span>
                            </button>
                            <button type="button" class="ar-tipo-btn" data-tipo="product" onclick="arSelecionarTipo('product')">
                                <i class="ph ph-shopping-bag"></i><span>Produto</span>
                            </button>
                            <button type="button" class="ar-tipo-btn" data-tipo="flow" onclick="arSelecionarTipo('flow')">
                                <i class="ph ph-flow-arrow"></i><span>Formulário</span>
                            </button>
                            <button type="button" class="ar-tipo-btn" data-tipo="location_request" onclick="arSelecionarTipo('location_request')">
                                <i class="ph ph-map-pin"></i><span>Localização</span>
                            </button>
                            <button type="button" class="ar-tipo-btn" data-tipo="sequencial" onclick="arSelecionarTipo('sequencial')">
                                <i class="ph ph-stack"></i><span>Sequencial</span>
                            </button>
                        </div>
                    </div>

                    <!-- Painéis de edição por tipo -->
                    <div class="ar-payload-panels">

                        <!-- TEXTO -->
                        <div class="ar-panel active" id="arPanel_texto">
                            <div class="dash-field">
                                <label>Mensagem de texto</label>
                                <textarea class="dash-input ar-textarea" id="arTexto" rows="4"
                                    placeholder="Digite a mensagem que será enviada..."></textarea>
                                <small class="dash-field__hint">Suporta emojis. Use {nome} para personalizar com o nome do contato.</small>
                            </div>
                        </div>

                        <!-- REPLY BUTTONS (até 3) -->
                        <div class="ar-panel" id="arPanel_reply_buttons">
                            <div class="dash-field">
                                <label>Mensagem principal</label>
                                <textarea class="dash-input" id="arRBBody" rows="3" placeholder="Texto acima dos botões..."></textarea>
                            </div>
                            <div class="ar-buttons-list" id="arRBList">
                                <div class="ar-btn-item">
                                    <span class="ar-btn-num">1</span>
                                    <input class="dash-input ar-btn-input" placeholder="Texto do botão 1" maxlength="20">
                                    <input class="dash-input ar-btn-id-input" placeholder="ID (ex: btn_1)" maxlength="20">
                                </div>
                                <div class="ar-btn-item">
                                    <span class="ar-btn-num">2</span>
                                    <input class="dash-input ar-btn-input" placeholder="Texto do botão 2" maxlength="20">
                                    <input class="dash-input ar-btn-id-input" placeholder="ID (ex: btn_2)" maxlength="20">
                                </div>
                                <div class="ar-btn-item">
                                    <span class="ar-btn-num">3</span>
                                    <input class="dash-input ar-btn-input" placeholder="Texto do botão 3 (opcional)" maxlength="20">
                                    <input class="dash-input ar-btn-id-input" placeholder="ID (ex: btn_3)" maxlength="20">
                                </div>
                            </div>
                            <small class="dash-field__hint">Até 3 botões de resposta rápida. O servidor enviará via whatsapp-web.js Button API.</small>
                        </div>

                        <!-- LIST MESSAGE -->
                        <div class="ar-panel" id="arPanel_list_message">
                            <div class="ar-grid-2">
                                <div class="dash-field">
                                    <label>Título da lista</label>
                                    <input class="dash-input" id="arListTitle" placeholder="Ex: Nossos serviços">
                                </div>
                                <div class="dash-field">
                                    <label>Texto do botão</label>
                                    <input class="dash-input" id="arListBtn" placeholder="Ex: Ver opções" maxlength="20">
                                </div>
                            </div>
                            <div class="dash-field">
                                <label>Descrição</label>
                                <textarea class="dash-input" id="arListDesc" rows="2" placeholder="Texto introdutório da lista..."></textarea>
                            </div>
                            <div id="arListSections">
                                <div class="ar-list-section">
                                    <div class="ar-list-section-header">
                                        <input class="dash-input ar-list-section-title" placeholder="Nome da seção (opcional)">
                                    </div>
                                    <div class="ar-list-rows">
                                        <div class="ar-list-row">
                                            <input class="dash-input" placeholder="Título do item" style="flex:2">
                                            <input class="dash-input" placeholder="Descrição" style="flex:3">
                                            <input class="dash-input" placeholder="ID" style="flex:1" maxlength="20">
                                        </div>
                                    </div>
                                    <button type="button" class="ar-add-row-btn" onclick="arAddListRow(this)">
                                        <i class="ph ph-plus"></i> Adicionar item
                                    </button>
                                </div>
                            </div>
                            <button type="button" class="ar-secondary-btn" onclick="arAddListSection()" style="margin-top:8px">
                                <i class="ph ph-plus"></i> Nova seção
                            </button>
                        </div>

                        <!-- CTA BUTTON -->
                        <div class="ar-panel" id="arPanel_cta_button">
                            <div class="dash-field">
                                <label>Mensagem</label>
                                <textarea class="dash-input" id="arCtaBody" rows="3" placeholder="Texto da mensagem..."></textarea>
                            </div>
                            <div class="ar-grid-2">
                                <div class="dash-field">
                                    <label>Tipo do botão CTA</label>
                                    <select class="dash-select" id="arCtaType" onchange="arToggleCtaType()">
                                        <option value="url">🔗 Abrir Site (URL)</option>
                                        <option value="phone">📞 Ligar</option>
                                    </select>
                                </div>
                                <div class="dash-field">
                                    <label>Texto do botão</label>
                                    <input class="dash-input" id="arCtaBtnText" placeholder="Ex: Acessar site" maxlength="20">
                                </div>
                            </div>
                            <div class="dash-field" id="arCtaUrlField">
                                <label>URL destino</label>
                                <input class="dash-input" id="arCtaUrl" placeholder="https://seusite.com.br" type="url">
                            </div>
                            <div class="dash-field" id="arCtaPhoneField" style="display:none">
                                <label>Número de telefone</label>
                                <input class="dash-input" id="arCtaPhone" placeholder="+5511999999999">
                            </div>
                        </div>

                        <!-- POLL / ENQUETE -->
                        <div class="ar-panel" id="arPanel_poll">
                            <div class="dash-field">
                                <label>Pergunta da enquete</label>
                                <input class="dash-input" id="arPollQuestion" placeholder="Ex: Qual serviço te interessa?">
                            </div>
                            <div class="dash-field">
                                <label>Opções <small>(mínimo 2, máximo 12)</small></label>
                                <div id="arPollOptions">
                                    <div class="ar-poll-option"><input class="dash-input" placeholder="Opção 1"><button type="button" onclick="arRemovePollOpt(this)" class="ar-rm-btn"><i class="ph ph-x"></i></button></div>
                                    <div class="ar-poll-option"><input class="dash-input" placeholder="Opção 2"><button type="button" onclick="arRemovePollOpt(this)" class="ar-rm-btn"><i class="ph ph-x"></i></button></div>
                                    <div class="ar-poll-option"><input class="dash-input" placeholder="Opção 3"><button type="button" onclick="arRemovePollOpt(this)" class="ar-rm-btn"><i class="ph ph-x"></i></button></div>
                                </div>
                                <button type="button" class="ar-add-row-btn" onclick="arAddPollOpt()" style="margin-top:8px">
                                    <i class="ph ph-plus"></i> Adicionar opção
                                </button>
                            </div>
                            <div class="dash-field">
                                <label class="ar-checkbox-label">
                                    <input type="checkbox" id="arPollMultiple"> Permitir múltipla escolha
                                </label>
                            </div>
                        </div>

                        <!-- PRODUCT MESSAGE -->
                        <div class="ar-panel" id="arPanel_product">
                            <div class="ar-info-box">
                                <i class="ph ph-info"></i>
                                <span>Requer catálogo de produtos ativo no WhatsApp Business. Informe o Business Catalog ID e o Product ID.</span>
                            </div>
                            <div class="ar-grid-2">
                                <div class="dash-field">
                                    <label>Business Catalog ID</label>
                                    <input class="dash-input" id="arProdCatalogId" placeholder="Ex: 123456789">
                                </div>
                                <div class="dash-field">
                                    <label>Product Retailer ID</label>
                                    <input class="dash-input" id="arProdRetailerId" placeholder="Ex: SKU-001">
                                </div>
                            </div>
                            <div class="dash-field">
                                <label>Mensagem introdutória <small>(opcional)</small></label>
                                <textarea class="dash-input" id="arProdBody" rows="2" placeholder="Veja nosso produto..."></textarea>
                            </div>
                        </div>

                        <!-- FLOW -->
                        <div class="ar-panel" id="arPanel_flow">
                            <div class="ar-info-box">
                                <i class="ph ph-info"></i>
                                <span>Flows são formulários nativos do WhatsApp Business. Requer Flow ID criado no Meta Business Suite.</span>
                            </div>
                            <div class="ar-grid-2">
                                <div class="dash-field">
                                    <label>Flow ID</label>
                                    <input class="dash-input" id="arFlowId" placeholder="Ex: 123456789">
                                </div>
                                <div class="dash-field">
                                    <label>CTA Text</label>
                                    <input class="dash-input" id="arFlowCta" placeholder="Ex: Preencher formulário">
                                </div>
                            </div>
                            <div class="dash-field">
                                <label>Mensagem do Flow</label>
                                <textarea class="dash-input" id="arFlowBody" rows="2" placeholder="Preencha nosso formulário..."></textarea>
                            </div>
                            <div class="dash-field">
                                <label>Header text <small>(opcional)</small></label>
                                <input class="dash-input" id="arFlowHeader" placeholder="Ex: Cadastro rápido">
                            </div>
                        </div>

                        <!-- LOCATION REQUEST -->
                        <div class="ar-panel" id="arPanel_location_request">
                            <div class="dash-field">
                                <label>Mensagem ao pedir localização</label>
                                <textarea class="dash-input" id="arLocBody" rows="3"
                                    placeholder="Para calcular o frete, preciso da sua localização 📍"></textarea>
                                <small class="dash-field__hint">O WhatsApp exibirá um botão nativo para o usuário compartilhar sua localização.</small>
                            </div>
                        </div>

                        <!-- SEQUENCIAL -->
                        <div class="ar-panel" id="arPanel_sequencial">
                            <div class="ar-info-box">
                                <i class="ph ph-info"></i>
                                <span>Envia múltiplas mensagens em sequência, com delay individual entre cada uma. Ideal para simular digitação humana.</span>
                            </div>
                            <div id="arSeqMessages">
                                <div class="ar-seq-item">
                                    <div class="ar-seq-header">
                                        <span class="ar-seq-num">Mensagem 1</span>
                                        <div class="ar-seq-delay">
                                            <label>Delay antes (seg)</label>
                                            <input type="number" class="dash-input ar-seq-delay-input" value="2" min="0" max="60" style="width:70px">
                                        </div>
                                        <button type="button" onclick="arRemoveSeqMsg(this)" class="ar-rm-btn"><i class="ph ph-trash"></i></button>
                                    </div>
                                    <textarea class="dash-input ar-seq-text" rows="2" placeholder="Primeira mensagem..."></textarea>
                                </div>
                            </div>
                            <button type="button" class="ar-secondary-btn" onclick="arAddSeqMsg()" style="margin-top:10px">
                                <i class="ph ph-plus"></i> Adicionar mensagem
                            </button>
                        </div>

                    </div><!-- /.ar-payload-panels -->

                    <div class="dash-form__footer ar-form-footer">
                        <?php if ($isFreePlan && count($autoRespostas) >= 5): ?>
                        <button type="button" class="btn-dash btn-dash--primary" disabled title="Limite de 5 auto respostas atingido" style="opacity:.5;cursor:not-allowed;">
                            <i class="ph ph-lock"></i> Limite Atingido (5/5)
                        </button>
                        <?php else: ?>
                        <button type="submit" class="btn-dash btn-dash--primary" onclick="arBuildPayload()">
                            <i class="ph ph-floppy-disk"></i> Salvar Auto Resposta
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- ── Lista de Auto Respostas Cadastradas ── -->
            <div class="dash-card" style="margin-top:24px;">
                <div class="dash-card__header">
                    <i class="ph ph-list-dashes"></i>
                    <h2>Respostas Cadastradas <span class="ar-count-badge"><?= count($autoRespostas) ?></span></h2>
                </div>

                <?php if (empty($autoRespostas)): ?>
                <div class="ar-empty-state">
                    <i class="ph ph-robot ar-empty-icon"></i>
                    <p>Nenhuma auto resposta cadastrada ainda.</p>
                    <small>Crie sua primeira regra acima e ela aparecerá aqui.</small>
                </div>
                <?php else: ?>
                <div class="ar-list">
                    <?php foreach ($autoRespostas as $ar): ?>
                    <?php
                        $arGatilhos = json_decode($ar['gatilhos'], true) ?: [];
                        $arPayload  = json_decode($ar['payload'],   true) ?: [];
                        $arTipo     = $ar['tipo_mensagem'];
                        $tipoLabel  = [
                            'texto'            => ['🗨️',  'Texto'],
                            'reply_buttons'    => ['🔲',  'Reply Buttons'],
                            'list_message'     => ['📋',  'List Message'],
                            'cta_button'       => ['🔗',  'CTA Button'],
                            'poll'             => ['📊',  'Enquete'],
                            'product'          => ['🛒',  'Product'],
                            'flow'             => ['📝',  'Flow'],
                            'location_request' => ['📍',  'Localização'],
                            'sequencial'       => ['📨',  'Sequencial'],
                        ][$arTipo] ?? ['💬', $arTipo];
                    ?>
                    <div class="ar-item <?= $ar['ativo'] ? 'ar-item--ativo' : 'ar-item--pausado' ?>">
                        <div class="ar-item__left">
                            <div class="ar-item__tipo-badge"><?= $tipoLabel[0] ?> <?= e($tipoLabel[1]) ?></div>
                            <div class="ar-item__nome"><?= e($ar['nome']) ?></div>
                            <div class="ar-item__gatilhos">
                                <?php foreach ($arGatilhos as $g): ?>
                                <span class="ar-gatilho-tag"><?= e($g) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <div class="ar-item__meta">
                                <span><i class="ph ph-clock"></i> <?= $ar['delay_min'] ?>s – <?= $ar['delay_max'] ?>s</span>
                                <span><i class="ph ph-stack"></i> Ordem: <?= $ar['ordem'] ?></span>
                                <span class="ar-status-pill <?= $ar['ativo'] ? 'ar-status-pill--on' : 'ar-status-pill--off' ?>">
                                    <?= $ar['ativo'] ? 'Ativa' : 'Pausada' ?>
                                </span>
                            </div>
                        </div>
                        <div class="ar-item__actions">
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="acao" value="toggle_auto_resposta">
                                <input type="hidden" name="ar_id" value="<?= $ar['id'] ?>">
                                <button type="submit" class="ar-action-btn ar-action-btn--toggle" title="<?= $ar['ativo'] ? 'Pausar' : 'Ativar' ?>">
                                    <i class="ph ph-<?= $ar['ativo'] ? 'pause-circle' : 'play-circle' ?>"></i>
                                </button>
                            </form>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Remover esta auto resposta?')">
                                <input type="hidden" name="acao" value="del_auto_resposta">
                                <input type="hidden" name="ar_id" value="<?= $ar['id'] ?>">
                                <button type="submit" class="ar-action-btn ar-action-btn--del" title="Excluir">
                                    <i class="ph ph-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>


        </div><!-- /.dash-section.ar-section -->

        <script>
        // ═══════════════════════════════════════════════════════════════════
        // AUTO RESPOSTAS — JavaScript completo
        // ═══════════════════════════════════════════════════════════════════
        let arTipoAtual = 'texto';

        function arSelecionarTipo(tipo) {
            arTipoAtual = tipo;
            document.getElementById('arTipoHidden').value = tipo;
            document.querySelectorAll('.ar-tipo-btn').forEach(b => {
                b.classList.toggle('active', b.dataset.tipo === tipo);
            });
            document.querySelectorAll('.ar-panel').forEach(p => p.classList.remove('active'));
            const panel = document.getElementById('arPanel_' + tipo);
            if (panel) panel.classList.add('active');
            arBuildPayload();
        }

        function arBuildPayload() {
            let payload = {};
            switch (arTipoAtual) {
                case 'texto':
                    payload = { texto: document.getElementById('arTexto')?.value || '' };
                    break;
                case 'reply_buttons': {
                    const items = document.querySelectorAll('#arRBList .ar-btn-item');
                    const buttons = [];
                    items.forEach(item => {
                        const txt = item.querySelector('.ar-btn-input')?.value?.trim();
                        const id  = item.querySelector('.ar-btn-id-input')?.value?.trim();
                        if (txt) buttons.push({ id: id || txt.toLowerCase().replace(/\s+/g,'_'), text: txt });
                    });
                    payload = { body: document.getElementById('arRBBody')?.value || '', buttons };
                    break;
                }
                case 'list_message': {
                    const sections = [];
                    document.querySelectorAll('#arListSections .ar-list-section').forEach(sec => {
                        const title = sec.querySelector('.ar-list-section-title')?.value?.trim() || '';
                        const rows = [];
                        sec.querySelectorAll('.ar-list-row').forEach(row => {
                            const inputs = row.querySelectorAll('input');
                            const t = inputs[0]?.value?.trim();
                            if (t) rows.push({ title: t, description: inputs[1]?.value?.trim() || '', rowId: inputs[2]?.value?.trim() || t.toLowerCase().replace(/\s+/g,'_') });
                        });
                        if (rows.length) sections.push({ title, rows });
                    });
                    payload = {
                        title:       document.getElementById('arListTitle')?.value || '',
                        description: document.getElementById('arListDesc')?.value  || '',
                        buttonText:  document.getElementById('arListBtn')?.value   || 'Ver opções',
                        sections
                    };
                    break;
                }
                case 'cta_button': {
                    const ctaType = document.getElementById('arCtaType')?.value || 'url';
                    payload = {
                        body:    document.getElementById('arCtaBody')?.value    || '',
                        btnText: document.getElementById('arCtaBtnText')?.value || '',
                        type:    ctaType,
                        url:     ctaType === 'url'   ? document.getElementById('arCtaUrl')?.value   || '' : '',
                        phone:   ctaType === 'phone' ? document.getElementById('arCtaPhone')?.value || '' : '',
                    };
                    break;
                }
                case 'poll': {
                    const opts = [];
                    document.querySelectorAll('#arPollOptions input[type="text"], #arPollOptions input:not([type])').forEach(i => {
                        const v = i.value?.trim();
                        if (v) opts.push(v);
                    });
                    payload = {
                        question:        document.getElementById('arPollQuestion')?.value || '',
                        options:         opts,
                        allowMultiple:   document.getElementById('arPollMultiple')?.checked || false,
                    };
                    break;
                }
                case 'product':
                    payload = {
                        catalogId:   document.getElementById('arProdCatalogId')?.value  || '',
                        retailerId:  document.getElementById('arProdRetailerId')?.value || '',
                        body:        document.getElementById('arProdBody')?.value        || '',
                    };
                    break;
                case 'flow':
                    payload = {
                        flowId:   document.getElementById('arFlowId')?.value     || '',
                        ctaText:  document.getElementById('arFlowCta')?.value    || '',
                        body:     document.getElementById('arFlowBody')?.value   || '',
                        header:   document.getElementById('arFlowHeader')?.value || '',
                    };
                    break;
                case 'location_request':
                    payload = { body: document.getElementById('arLocBody')?.value || '' };
                    break;
                case 'sequencial': {
                    const msgs = [];
                    document.querySelectorAll('#arSeqMessages .ar-seq-item').forEach(item => {
                        const txt   = item.querySelector('.ar-seq-text')?.value?.trim();
                        const delay = parseInt(item.querySelector('.ar-seq-delay-input')?.value) || 2;
                        if (txt) msgs.push({ texto: txt, delay });
                    });
                    payload = { messages: msgs };
                    break;
                }
            }
            const json = JSON.stringify(payload, null, 2);
            document.getElementById('arPayloadHidden').value = JSON.stringify(payload);
            const pre = document.getElementById('arPreviewJson');
            if (pre) pre.textContent = json;
            return payload;
        }

        function arTogglePreview() {
            arBuildPayload();
            const box = document.getElementById('arPreviewBox');
            box.style.display = box.style.display === 'none' ? 'block' : 'none';
        }

        function arToggleCtaType() {
            const type = document.getElementById('arCtaType')?.value;
            document.getElementById('arCtaUrlField').style.display   = type === 'url'   ? '' : 'none';
            document.getElementById('arCtaPhoneField').style.display = type === 'phone' ? '' : 'none';
        }

        function arAddListRow(btn) {
            const wrap = btn.previousElementSibling;
            const div = document.createElement('div');
            div.className = 'ar-list-row';
            div.innerHTML = '<input class="dash-input" placeholder="Título do item" style="flex:2"><input class="dash-input" placeholder="Descrição" style="flex:3"><input class="dash-input" placeholder="ID" style="flex:1" maxlength="20"><button type="button" onclick="this.closest(\'.ar-list-row\').remove()" class="ar-rm-btn"><i class="ph ph-x"></i></button>';
            wrap.appendChild(div);
        }

        function arAddListSection() {
            const container = document.getElementById('arListSections');
            const div = document.createElement('div');
            div.className = 'ar-list-section';
            div.innerHTML = `<div class="ar-list-section-header">
                <input class="dash-input ar-list-section-title" placeholder="Nome da seção (opcional)">
                <button type="button" onclick="this.closest('.ar-list-section').remove()" class="ar-rm-btn"><i class="ph ph-trash"></i></button>
            </div>
            <div class="ar-list-rows">
                <div class="ar-list-row">
                    <input class="dash-input" placeholder="Título do item" style="flex:2">
                    <input class="dash-input" placeholder="Descrição" style="flex:3">
                    <input class="dash-input" placeholder="ID" style="flex:1" maxlength="20">
                </div>
            </div>
            <button type="button" class="ar-add-row-btn" onclick="arAddListRow(this)"><i class="ph ph-plus"></i> Adicionar item</button>`;
            container.appendChild(div);
        }

        function arAddPollOpt() {
            const n = document.querySelectorAll('#arPollOptions .ar-poll-option').length + 1;
            if (n > 12) return;
            const div = document.createElement('div');
            div.className = 'ar-poll-option';
            div.innerHTML = `<input class="dash-input" placeholder="Opção ${n}"><button type="button" onclick="arRemovePollOpt(this)" class="ar-rm-btn"><i class="ph ph-x"></i></button>`;
            document.getElementById('arPollOptions').appendChild(div);
        }

        function arRemovePollOpt(btn) {
            const opts = document.querySelectorAll('#arPollOptions .ar-poll-option');
            if (opts.length > 2) btn.closest('.ar-poll-option').remove();
        }

        function arAddSeqMsg() {
            const n = document.querySelectorAll('#arSeqMessages .ar-seq-item').length + 1;
            const div = document.createElement('div');
            div.className = 'ar-seq-item';
            div.innerHTML = `<div class="ar-seq-header">
                <span class="ar-seq-num">Mensagem ${n}</span>
                <div class="ar-seq-delay"><label>Delay antes (seg)</label><input type="number" class="dash-input ar-seq-delay-input" value="3" min="0" max="60" style="width:70px"></div>
                <button type="button" onclick="arRemoveSeqMsg(this)" class="ar-rm-btn"><i class="ph ph-trash"></i></button>
            </div>
            <textarea class="dash-input ar-seq-text" rows="2" placeholder="Mensagem ${n}..."></textarea>`;
            document.getElementById('arSeqMessages').appendChild(div);
        }

        function arRemoveSeqMsg(btn) {
            const items = document.querySelectorAll('#arSeqMessages .ar-seq-item');
            if (items.length > 1) btn.closest('.ar-seq-item').remove();
        }

        function arVerPayload(rawPayload) {
            try {
                const parsed = typeof rawPayload === 'string' ? JSON.parse(rawPayload) : rawPayload;
                document.getElementById('arModalJson').textContent = JSON.stringify(parsed, null, 2);
            } catch(e) {
                document.getElementById('arModalJson').textContent = rawPayload;
            }
            document.getElementById('arPayloadModal').style.display = 'flex';
        }

        function arFecharModal() {
            document.getElementById('arPayloadModal').style.display = 'none';
        }

        document.addEventListener('keydown', e => { if (e.key === 'Escape') arFecharModal(); });

        // Build payload antes de submeter
        document.getElementById('arMainForm')?.addEventListener('submit', function() {
            arBuildPayload();
        });
        </script>
        <?php endif; ?>
        <!-- ════════════════════════════════════════════════════════════════════
             ABA: CONFIGURAÇÕES
        ═══════════════════════════════════════════════════════════════════════ -->
        <?php if ($aba === 'config'): ?>
        <div class="dash-section">

            <div class="dash-section__header">
                <h1 class="dash-section__title">
                    <i class="ph ph-sliders-horizontal"></i>
                    Configurações
                </h1>
                <p class="dash-section__sub">Ajuste os parâmetros da IA e comportamentos do bot.</p>
            </div>

            <form method="POST" class="dash-form">
                <input type="hidden" name="acao" value="salvar_config">

                <!-- IA -->
                <div class="dash-card">
                    <div class="dash-card__header">
                        <i class="ph ph-robot"></i>
                        <h2>Parâmetros da IA</h2>
                        <?php if (!$limTudo): ?><span class="dash-free-badge"><i class="ph ph-lock-simple"></i> Limitado</span><?php endif; ?>
                    </div>

                    <?php if (!$limTudo): ?>
                    <div class="dash-free-notice dash-free-notice--compact">
                        <i class="ph ph-info"></i>
                        <span>Seu plano: Temperature ≤ <strong><?= $freeLimits['temperature'] ?></strong> &middot; Tokens ≤ <strong><?= $freeLimits['max_tokens'] ?></strong> &middot; Caracteres ≤ <strong><?= $freeLimits['max_chars'] ?></strong>.</span>
                    </div>
                    <?php endif; ?>

                    <div class="dash-config-grid">

                        <div class="dash-config-item">
                            <label>Temperature: <strong id="lblTemp"><?= e(min((float)$cfg['temperature'], $freeLimits['temperature'])) ?></strong>
                                <?php if (!$limTudo): ?><small style="display:inline;color:var(--dash-muted);"> / 2.0 máx.</small><?php endif; ?>
                            </label>
                            <small>Criatividade (0 = preciso, 2 = criativo<?= !$limTudo ? ' &mdash; seu plano: máx. '.$freeLimits['temperature'] : '' ?>)</small>
                            <input type="range" name="temperature" min="0" max="2" step="0.1"
                                   value="<?= e(min((float)$cfg['temperature'], $freeLimits['temperature'])) ?>"
                                   data-plan-max="<?= $freeLimits['temperature'] ?>"
                                   <?= !$limTudo ? 'data-free-max="'.$freeLimits['temperature'].'"' : '' ?>
                                   oninput="handleFreeRange(this, 'lblTemp', function(v){ return parseFloat(v).toFixed(1); })">
                        </div>

                        <div class="dash-config-item">
                            <label>Máx. Tokens: <strong id="lblTokens"><?= e(min((int)$cfg['max_tokens'], $freeLimits['max_tokens'])) ?></strong>
                                <?php if (!$limTudo): ?><small style="display:inline;color:var(--dash-muted);"> / <?= $globalLimits['max_tokens'] ?> máx.</small><?php endif; ?>
                            </label>
                            <small>Tokens por resposta (10–<?= $globalLimits['max_tokens'] ?>)<?= !$limTudo ? ' &mdash; seu plano: até '.$freeLimits['max_tokens'] : '' ?></small>
                            <input type="range" name="max_tokens" min="10" max="<?= $globalLimits['max_tokens'] ?>" step="1"
                                   value="<?= e(min((int)$cfg['max_tokens'], $freeLimits['max_tokens'])) ?>"
                                   data-plan-max="<?= $freeLimits['max_tokens'] ?>"
                                   <?= !$limTudo ? 'data-free-max="'.$freeLimits['max_tokens'].'"' : '' ?>
                                   oninput="handleFreeRange(this, 'lblTokens', function(v){ return v; })">
                        </div>

                        <div class="dash-config-item">
                            <label>Máx. Caracteres: <strong id="lblChars"><?= e(min((int)$cfg['max_chars'], $freeLimits['max_chars'])) ?></strong>
                                <?php if (!$limTudo): ?><small style="display:inline;color:var(--dash-muted);"> / <?= $globalLimits['max_chars'] ?> máx.</small><?php endif; ?>
                            </label>
                            <small>Corta após N chars (50–<?= $globalLimits['max_chars'] ?>)<?= !$limTudo ? ' &mdash; seu plano: até '.$freeLimits['max_chars'] : '' ?></small>
                            <input type="range" name="max_chars" min="50" max="<?= $globalLimits['max_chars'] ?>" step="1"
                                   value="<?= e(min((int)$cfg['max_chars'], $freeLimits['max_chars'])) ?>"
                                   data-plan-max="<?= $freeLimits['max_chars'] ?>"
                                   <?= !$limTudo ? 'data-free-max="'.$freeLimits['max_chars'].'"' : '' ?>
                                   oninput="handleFreeRange(this, 'lblChars', function(v){ return v; })">
                        </div>

                    </div>
                </div>

                <!-- Comportamento -->                <!-- Comportamento -->
                <div class="dash-card">
                    <div class="dash-card__header">
                        <i class="ph ph-gear-six"></i>
                        <h2>Comportamento</h2>
                    </div>
                    <div class="dash-config-form-grid">

                        <div class="dash-field">
                            <label>Tom de resposta</label>
                            <select name="modo_resposta" class="dash-select">
                                <?php foreach (['natural' => 'Natural', 'formal' => 'Formal', 'amigável' => 'Amigável', 'sedutor' => 'Sedutor'] as $v => $l): ?>
                                <option value="<?= e($v) ?>" <?= $cfg['modo_resposta'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="dash-field">
                            <label>Idioma</label>
                            <select name="idioma" class="dash-select">
                                <option value="pt-BR" <?= $cfg['idioma'] === 'pt-BR' ? 'selected' : '' ?>>Português (BR)</option>
                                <option value="en-US" <?= $cfg['idioma'] === 'en-US' ? 'selected' : '' ?>>English (US)</option>
                                <option value="es"    <?= $cfg['idioma'] === 'es'    ? 'selected' : '' ?>>Español</option>
                            </select>
                        </div>

                        <div class="dash-field">
                            <label>Horário de início</label>
                            <input type="time" name="horario_inicio" class="dash-input"
                                   value="<?= e($cfg['horario_inicio']) ?>">
                        </div>

                        <div class="dash-field">
                            <label>Horário de fim</label>
                            <input type="time" name="horario_fim" class="dash-input"
                                   value="<?= e($cfg['horario_fim']) ?>">
                        </div>

                        <div class="dash-field dash-field--full">
                            <label>Palavras bloqueadas <small>(separadas por vírgula)</small></label>
                            <input type="text" name="blacklist" class="dash-input"
                                   value="<?= e($cfg['blacklist']) ?>"
                                   placeholder="spam, propaganda, pirata">
                        </div>

                        <div class="dash-field dash-field--full">
                            <label>Mensagem fora do horário <small>(vazio = silêncio)</small></label>
                            <input type="text" name="fora_horario_msg" class="dash-input"
                                   value="<?= e($cfg['fora_horario_msg']) ?>"
                                   placeholder="No momento estou fora do horário de atendimento.">
                        </div>

                    </div>

                    <div class="dash-toggles-grid dash-toggles-grid--compact" style="margin-top:24px;">
                        <?php if ($iaTrialExpirado): ?>
                        <!-- IA Ativa bloqueada: plano free expirado -->
                        <label class="dash-toggle dash-toggle--locked" title="Seu período gratuito expirou. Faça upgrade para reativar a IA.">
                            <input type="checkbox" name="ia_ativo" disabled>
                            <span class="dash-toggle__slider dash-toggle__slider--locked"></span>
                            <span class="dash-toggle__info">
                                <span class="dash-toggle__label">
                                    IA Ativa
                                    <span class="dash-lock-icon"><i class="ph ph-lock-simple"></i> Trial encerrado</span>
                                </span>
                                <span class="dash-toggle__desc">Seu período gratuito expirou. <a href="/planos" style="color:#d97706;font-weight:700;">Faça upgrade</a> para reativar o bot.</span>
                            </span>
                        </label>
                        <input type="hidden" name="ia_ativo" value="0">
                        <?php else: ?>
                        <label class="dash-toggle">
                            <input type="checkbox" name="ia_ativo" <?= $cfg['ia_ativo'] === '1' ? 'checked' : '' ?>>
                            <span class="dash-toggle__slider"></span>
                            <span class="dash-toggle__info">
                                <span class="dash-toggle__label">IA Ativa</span>
                                <span class="dash-toggle__desc">Desative para pausar o bot sem desconectar o WhatsApp</span>
                            </span>
                        </label>
                        <?php endif; ?>

                        <?php if ($iaTrialExpirado): ?>
                        <!-- AR Ativa bloqueada: plano free expirado -->
                        <label class="dash-toggle dash-toggle--locked" title="Seu período gratuito expirou. Faça upgrade para reativar as Auto Respostas.">
                            <input type="checkbox" name="ar_ativo" disabled>
                            <span class="dash-toggle__slider dash-toggle__slider--locked"></span>
                            <span class="dash-toggle__info">
                                <span class="dash-toggle__label">
                                    AR Ativas
                                    <span class="dash-lock-icon"><i class="ph ph-lock-simple"></i> Trial encerrado</span>
                                </span>
                                <span class="dash-toggle__desc">Seu período gratuito expirou. <a href="/planos" style="color:#d97706;font-weight:700;">Faça upgrade</a> para reativar as Auto Respostas.</span>
                            </span>
                        </label>
                        <input type="hidden" name="ar_ativo" value="0">
                        <?php else: ?>
                        <label class="dash-toggle">
                            <input type="checkbox" name="ar_ativo" <?= $cfg['ar_ativo'] === '1' ? 'checked' : '' ?>>
                            <span class="dash-toggle__slider"></span>
                            <span class="dash-toggle__info">
                                <span class="dash-toggle__label">AR Ativas</span>
                                <span class="dash-toggle__desc">Desative para pausar só as Auto Respostas sem afetar a IA</span>
                            </span>
                        </label>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="dash-form__footer">
                    <button type="submit" class="btn-dash btn-dash--primary btn-dash--lg">
                        <i class="ph ph-floppy-disk"></i>
                        Salvar Configurações
                    </button>
                </div>
            </form>

        </div><!-- /.dash-section -->
        <?php endif; ?>

    </main><!-- /.dash-main -->
</div><!-- /.dash-root -->

<!-- ════════════════════════════════════════════════════════════════════════════
     AJAX: Update sessão WA
════════════════════════════════════════════════════════════════════════════ -->
<?php
// Handler AJAX inline (evita arquivo extra)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'update_sessao') {
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input && !empty($input['session_id'])) {
        $novoStatus = $input['status'] ?? 'desconectado';
        $fotoBase64 = $input['foto']   ?? null;

        // Atualiza sessão WhatsApp
        $pdo->prepare("
            UPDATE bot_whatsapp_sessoes
            SET status = ?, numero = ?, nome_conta = ?, foto_url = ?
            WHERE session_id = ? AND usuario_id = ?
        ")->execute([
            $novoStatus,
            $input['numero'] ?? null,
            $input['nome']   ?? null,
            $fotoBase64,
            $input['session_id'],
            $userId,
        ]);

        // Ao conectar: salva a foto como avatar_url definitivo na tabela usuarios
        if ($novoStatus === 'conectado' && !empty($fotoBase64) && str_starts_with($fotoBase64, 'data:image')) {
            $pdo->prepare("
                UPDATE usuarios SET avatar_url = ? WHERE id = ?
            ")->execute([$fotoBase64, $userId]);
        }

        echo json_encode(['ok' => true]);
    }
    exit;
}
?>

<!-- ══════════════════════════════════════════════════════════════════════════
     ESTILOS DO DASHBOARD
════════════════════════════════════════════════════════════════════════════ -->
<style>
/* Ocultar banners quando o plano não exibe banners */
.dash-no-banners .dash-free-notice,
.dash-no-banners .dash-free-banner,
.dash-no-banners .ms-upgrade-banner {
    display: none !important;
}

/* ── Variáveis (herda do site) ─────────────────────────────────────────── */
:root {
    --dash-sidebar-w:  240px;
    --dash-accent:     var(--color-accent, #00c060);
    --dash-accent-dim: var(--color-accent-dim, #e6faf2);
    --dash-accent-dk:  var(--color-accent-dark, #007a3d);
    --dash-bg:         var(--color-bg, #f7faf9);
    --dash-surface:    var(--color-surface, #ffffff);
    --dash-border:     var(--color-border, #e2ebe6);
    --dash-text:       var(--color-text, #0f1f16);
    --dash-muted:      var(--color-text-muted, #5a7a67);
    --dash-radius:     var(--radius-lg, 14px);
    --dash-shadow:     var(--shadow-sm, 0 1px 4px rgba(0,0,0,.07));
    --dash-shadow-md:  var(--shadow-md, 0 4px 16px rgba(0,0,0,.1));
    --color-danger:    #e53e3e;
    --color-warning:   #d97706;
}

/* ── Layout raiz ───────────────────────────────────────────────────────── */
.dash-root {
    display: flex;
    min-height: 100vh;
    background: var(--dash-bg);
}

/* ════════════════════════════════════════════════════════════════════════
   SIDEBAR
════════════════════════════════════════════════════════════════════════ */
.dash-sidebar {
    width: var(--dash-sidebar-w);
    background: var(--dash-surface);
    border-right: 1px solid var(--dash-border);
    display: flex;
    flex-direction: column;
    position: sticky;
    top: 0;
    height: 100vh;
    overflow-y: auto;
    flex-shrink: 0;
    z-index: 100;
}

.dash-sidebar__logo {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 24px 20px 20px;
    border-bottom: 1px solid var(--dash-border);
}

.dash-sidebar__logo-icon {
    width: 34px;
    height: 34px;
    background: var(--dash-accent);
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.dash-sidebar__logo-icon svg {
    width: 20px;
    height: 20px;
}

.dash-sidebar__logo-name {
    font-family: var(--font-display, 'Syne', sans-serif);
    font-weight: 800;
    font-size: 1.2rem;
    color: var(--dash-text);
    letter-spacing: -.03em;
}

.dash-sidebar__logo-name em {
    font-style: normal;
    color: var(--dash-accent);
}

/* ── Nav ── */
.dash-nav {
    padding: 16px 12px;
    display: flex;
    flex-direction: column;
    gap: 0;
}

.dash-nav__item {
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 10px;
    color: var(--dash-muted);
    text-decoration: none;
    font-size: .875rem;
    font-weight: 500;
    transition: background .18s, color .18s;
    position: relative;
    -webkit-tap-highlight-color: transparent;
    outline: none;
}

.dash-nav__item:visited,
.dash-nav__item:focus,
.dash-nav__item:active {
    color: var(--dash-muted);
    outline: none;
}

.dash-nav__item i {
    font-size: 1.1rem;
    flex-shrink: 0;
}

.dash-nav__item:hover {
    background: var(--dash-accent-dim);
    color: var(--dash-accent-dk);
}

.dash-nav__item.active {
    background: var(--dash-accent-dim);
    color: var(--dash-accent-dk);
    font-weight: 700;
}

.dash-nav__badge {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-left: auto;
    flex-shrink: 0;
}

.dash-nav__badge--green  { background: #22c55e; box-shadow: 0 0 0 2px #dcfce7; }
.dash-nav__badge--yellow { background: #f59e0b; box-shadow: 0 0 0 2px #fef3c7; }
.dash-nav__badge--red    { background: #ef4444; box-shadow: 0 0 0 2px #fee2e2; }

/* ── WhatsApp nav item premium ── */
.dash-nav__item--wa {
    background: rgba(37,211,102,.08);
    border: 1px solid rgba(37,211,102,.3);
    color: var(--dash-text) !important;
    font-weight: 500;
    border-radius: 10px;
    margin-bottom: 4px;
    transition: background .18s, border-color .18s, color .18s;
}
.dash-nav__item--wa:visited,
.dash-nav__item--wa:focus,
.dash-nav__item--wa:active {
    color: var(--dash-text) !important;
    outline: none;
}
.dash-nav__item--wa:hover {
    background: rgba(37,211,102,.14) !important;
    border-color: rgba(37,211,102,.5) !important;
    color: #0a6630 !important;
}
.dash-nav__item--wa.active {
    background: rgba(37,211,102,.14) !important;
    border-color: rgba(37,211,102,.5) !important;
    color: #0a6630 !important;
    font-weight: 700;
}
.dash-nav__wa-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    background: linear-gradient(135deg, #25d366, #128c3e);
    border-radius: 8px;
    flex-shrink: 0;
    color: #fff;
    box-shadow: 0 2px 8px rgba(37,211,102,.4);
    transition: box-shadow .18s, filter .18s;
}
.dash-nav__item--wa:hover .dash-nav__wa-icon {
    filter: brightness(1.1);
    box-shadow: 0 3px 12px rgba(37,211,102,.55);
}

/* ── Botão Ganhar Dias (sempre visível no sidebar) ── */
.dash-nav__item--groq {
    background: var(--dash-accent-dim);
    border: 1px solid rgba(0,192,96,.25);
    color: var(--dash-text) !important;
}
.dash-nav__item--groq:hover, .dash-nav__item--groq.active {
    background: rgba(0,192,96,.18) !important;
    border-color: rgba(0,192,96,.45) !important;
    color: var(--dash-accent-dk) !important;
}
.dash-sidebar__extra {
    border-top: 1px solid var(--dash-border);
    padding: 12px;
}

/* ── Footer sidebar ── */
.dash-sidebar__footer {
    margin-top: auto;
    padding: 16px 12px;
    border-top: 1px solid var(--dash-border);
    display: flex;
    align-items: center;
    gap: 10px;
}

.dash-sidebar__avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--dash-accent);
    color: #fff;
    font-weight: 700;
    font-size: .85rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    overflow: hidden;
}

.dash-sidebar__avatar-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}

.dash-sidebar__user-info {
    flex: 1;
    min-width: 0;
    overflow: hidden;
}

.dash-sidebar__user-name {
    display: block;
    font-size: .8125rem;
    font-weight: 600;
    color: var(--dash-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.dash-sidebar__user-plan {
    display: block;
    font-size: .72rem;
    font-weight: 600;
    color: var(--dash-accent-dk);
}
.dash-sidebar__user-plan--trial  { color: #f59e0b; }
.dash-sidebar__user-plan--pago   { color: #34d399; }
.dash-sidebar__user-plan--expirado { color: #f87171; }

.dash-sidebar__user-expira {
    display: block;
    font-size: .67rem;
    color: var(--dash-muted);
    margin-top: 1px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.dash-sidebar__logout {
    color: var(--dash-muted);
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    padding: 6px;
    border-radius: 8px;
    transition: color .18s, background .18s;
    text-decoration: none;
    flex-shrink: 0;
}

.dash-sidebar__logout:hover {
    color: var(--color-danger);
    background: rgba(229,62,62,.1);
}

/* ════════════════════════════════════════════════════════════════════════
   MAIN
════════════════════════════════════════════════════════════════════════ */
.dash-main {
    flex: 1;
    min-width: 0;
    padding: 0 0 80px;
}

/* ── Topbar mobile ── */
.dash-topbar {
    display: none;
    align-items: center;
    gap: 12px;
    padding: 14px 20px;
    background: var(--dash-surface);
    border-bottom: 1px solid var(--dash-border);
    position: sticky;
    top: 0;
    z-index: 90;
}

.dash-topbar__toggle {
    background: none;
    border: none;
    font-size: 1.3rem;
    color: var(--dash-text);
    cursor: pointer;
    padding: 4px;
    border-radius: 6px;
    display: flex;
    align-items: center;
}

.dash-topbar__title {
    font-weight: 700;
    font-size: 1rem;
    flex: 1;
}

.dash-topbar__status {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: .78rem;
    color: var(--dash-muted);
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}

.status-dot--green { background: #22c55e; }
.status-dot--red   { background: #ef4444; }

/* ── Alerts ── */
.dash-alert {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 24px 32px 0;
    padding: 14px 18px;
    border-radius: var(--dash-radius);
    font-size: .875rem;
    font-weight: 500;
    animation: fadeUp .4s ease both;
}

.dash-alert--success {
    background: rgba(34,197,94,.1);
    border: 1px solid rgba(34,197,94,.3);
    color: var(--dash-text);
}

.dash-alert--error {
    background: rgba(239,68,68,.08);
    border: 1px solid rgba(239,68,68,.25);
    color: var(--dash-text);
}

/* ── Section ── */
.dash-section {
    padding: 32px;
}

.dash-section__header {
    margin-bottom: 28px;
}

.dash-section__title {
    font-family: var(--font-display, sans-serif);
    font-size: 1.5rem;
    font-weight: 800;
    letter-spacing: -.03em;
    color: var(--dash-text);
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 6px;
}

.dash-section__title i {
    color: var(--dash-accent);
    font-size: 1.4rem;
}

.dash-section__sub {
    color: var(--dash-muted);
    font-size: .9375rem;
}

/* ── Cards ── */
.dash-card {
    background: var(--dash-surface);
    border: 1px solid var(--dash-border);
    border-radius: var(--dash-radius);
    padding: 24px;
    margin-bottom: 20px;
    box-shadow: var(--dash-shadow);
}

.dash-card__header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
}

.dash-card__header i {
    font-size: 1.1rem;
    color: var(--dash-accent-dk);
}

.dash-card__header h2 {
    font-size: 1rem;
    font-weight: 700;
    color: var(--dash-text);
    margin: 0;
    flex: 1;
}

.dash-card__count {
    background: var(--dash-accent-dim);
    color: var(--dash-accent-dk);
    font-size: .72rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 20px;
}

.dash-card__desc {
    font-size: .85rem;
    color: var(--dash-muted);
    margin-bottom: 20px;
    line-height: 1.6;
}

/* ════════════════════════════════════════════════════════════════════════
   WHATSAPP CONNECT
════════════════════════════════════════════════════════════════════════ */
.wa-connect-grid {
    display: grid;
    grid-template-columns: 380px 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

.wa-connect-card {
    background: var(--dash-surface);
    border: 1px solid var(--dash-border);
    border-radius: var(--dash-radius);
    padding: 32px 24px;
    box-shadow: var(--dash-shadow);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 24px;
    text-align: center;
}

/* QR area */
.wa-qr-area {
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.wa-qr-placeholder,
.wa-qr-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 14px;
    width: 240px;
    height: 240px;
    border: 2px dashed var(--dash-border);
    border-radius: 16px;
    color: var(--dash-muted);
    font-size: .875rem;
}

.wa-qr-placeholder i { font-size: 3.5rem; color: var(--dash-border); }

.wa-qr-spinner {
    width: 40px;
    height: 40px;
    border: 3px solid var(--dash-border);
    border-top-color: var(--dash-accent);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

#waQrCanvas {
    border-radius: 12px;
    border: 4px solid var(--dash-accent);
}

.wa-qr-hint {
    font-size: .75rem;
    color: var(--dash-muted);
    margin-top: 12px;
    line-height: 1.5;
    max-width: 220px;
}

.wa-qr-timer {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: .8rem;
    color: var(--color-warning);
    font-weight: 600;
    margin-top: 8px;
}

/* Status connected */
.wa-status-connected {
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
}

.wa-status-connected__icon {
    font-size: 3.5rem;
    color: #22c55e;
    line-height: 1;
}

.wa-status-connected__title {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--dash-text);
}

.wa-account-info {
    display: flex;
    align-items: center;
    gap: 14px;
    background: var(--dash-accent-dim);
    border: 1px solid rgba(0,192,96,.2);
    border-radius: 12px;
    padding: 14px 20px;
    width: 100%;
    text-align: left;
}

.wa-account-info__avatar {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
}

.wa-account-info__avatar--fallback {
    background: var(--dash-accent);
    color: #fff;
    font-weight: 700;
    font-size: 1.2rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

.wa-account-info__name {
    display: block;
    font-weight: 700;
    font-size: .95rem;
    color: var(--dash-text);
}

.wa-account-info__number {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: .8125rem;
    color: var(--dash-muted);
    margin-top: 3px;
}

/* Actions WA */
.wa-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: center;
}

#btnAtualizarStatus {
    min-width: 160px;
    justify-content: center;
}

/* Info panel */
.wa-info-panel {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.wa-info-card {
    display: flex;
    flex-direction: column;
    gap: 14px;
    background: var(--dash-surface);
    border: 1px solid var(--dash-border);
    border-radius: 16px;
    padding: 22px 20px;
    box-shadow: var(--dash-shadow);
    position: relative;
    overflow: hidden;
    transition: box-shadow .22s, border-color .22s, transform .18s;
}

.wa-info-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--card-accent-a, var(--dash-accent)), var(--card-accent-b, var(--dash-accent-dk)));
    opacity: 0;
    transition: opacity .22s;
}

.wa-info-card:hover {
    border-color: var(--card-accent-a, var(--dash-accent));
    box-shadow: 0 6px 24px rgba(0,0,0,.09), 0 0 0 3px var(--card-glow, var(--dash-accent-dim));
    transform: translateY(-2px);
}

.wa-info-card:hover::before { opacity: 1; }

/* Card 1 – Sessões Independentes */
.wa-info-card:nth-of-type(1) {
    --card-accent-a: #00c060;
    --card-accent-b: #00b8a9;
    --card-glow: rgba(0,192,96,.10);
    --card-icon-bg: rgba(0,192,96,.10);
    --card-icon-color: #007a3d;
}

/* Card 2 – Como escanear */
.wa-info-card:nth-of-type(2) {
    --card-accent-a: #6366f1;
    --card-accent-b: #8b5cf6;
    --card-glow: rgba(99,102,241,.10);
    --card-icon-bg: rgba(99,102,241,.10);
    --card-icon-color: #4338ca;
}

/* Card 3 – IA por Sessão */
.wa-info-card:nth-of-type(3) {
    --card-accent-a: #0ea5e9;
    --card-accent-b: #06b6d4;
    --card-glow: rgba(14,165,233,.10);
    --card-icon-bg: rgba(14,165,233,.10);
    --card-icon-color: #0369a1;
}

/* Card 4 – Reconexão Automática */
.wa-info-card:nth-of-type(4) {
    --card-accent-a: #f59e0b;
    --card-accent-b: #f97316;
    --card-glow: rgba(245,158,11,.10);
    --card-icon-bg: rgba(245,158,11,.10);
    --card-icon-color: #b45309;
}

/* Card 5 – Segurança Total */
.wa-info-card:nth-of-type(5) {
    --card-accent-a: #10b981;
    --card-accent-b: #059669;
    --card-glow: rgba(16,185,129,.10);
    --card-icon-bg: rgba(16,185,129,.10);
    --card-icon-color: #065f46;
}

.wa-info-card__icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: var(--card-icon-bg, var(--dash-accent-dim));
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--card-icon-color, var(--dash-accent-dk));
    font-size: 1.3rem;
    flex-shrink: 0;
    transition: transform .2s var(--ease-spring, cubic-bezier(0.34,1.56,0.64,1));
}

.wa-info-card:hover .wa-info-card__icon {
    transform: scale(1.12) rotate(-4deg);
}

.wa-info-card h3 {
    font-size: .875rem;
    font-weight: 700;
    color: var(--dash-text);
    margin: 0 0 5px;
    letter-spacing: -.01em;
}

.wa-info-card p {
    font-size: .8rem;
    color: var(--dash-muted);
    margin: 0;
    line-height: 1.6;
}

/* Session info */
.wa-session-info {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    background: var(--dash-surface);
    border: 1px solid var(--dash-border);
    border-radius: 10px;
    padding: 12px 18px;
    font-size: .8rem;
}

.wa-session-info__label { color: var(--dash-muted); font-weight: 600; }
.wa-session-info__id    { font-family: monospace; color: var(--dash-text); font-size: .78rem; }
.wa-session-info__status { margin-left: auto; display: flex; align-items: center; gap: 6px; }

.badge-status {
    padding: 2px 10px;
    border-radius: 20px;
    font-size: .72rem;
    font-weight: 700;
}

.badge-status--green  { background: rgba(34,197,94,.12); color: #22c55e; border: 1px solid rgba(34,197,94,.3); }
.badge-status--yellow { background: rgba(245,158,11,.12); color: #f59e0b; border: 1px solid rgba(245,158,11,.3); }
.badge-status--red    { background: rgba(239,68,68,.08); color: #ef4444; border: 1px solid rgba(239,68,68,.25); }

/* ════════════════════════════════════════════════════════════════════════
   TOGGLES
════════════════════════════════════════════════════════════════════════ */
.dash-toggles-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
}

.dash-toggle {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px;
    border: 1px solid var(--dash-border);
    border-radius: 10px;
    cursor: pointer;
    transition: background .15s, border-color .15s;
}

.dash-toggle:hover {
    background: var(--dash-accent-dim);
    border-color: rgba(0,192,96,.2);
}

.dash-toggle input[type="checkbox"] {
    display: none;
}

.dash-toggle__slider {
    position: relative;
    width: 40px;
    height: 22px;
    background: var(--dash-border);
    border-radius: 11px;
    flex-shrink: 0;
    margin-top: 2px;
    transition: background .18s;
}

.dash-toggle__slider::after {
    content: '';
    position: absolute;
    top: 3px;
    left: 3px;
    width: 16px;
    height: 16px;
    background: #fff;
    border-radius: 50%;
    transition: transform .18s;
    box-shadow: 0 1px 3px rgba(0,0,0,.2);
}

.dash-toggle input:checked ~ .dash-toggle__slider {
    background: var(--dash-accent);
}

.dash-toggle input:checked ~ .dash-toggle__slider::after {
    transform: translateX(18px);
}

.dash-toggle__info { display: flex; flex-direction: column; gap: 2px; }
.dash-toggle__label { font-size: .875rem; font-weight: 600; color: var(--dash-text); }
.dash-toggle__desc  { font-size: .78rem; color: var(--dash-muted); line-height: 1.4; }

.dash-toggle--inline {
    border: none;
    padding: 0;
}

/* ════════════════════════════════════════════════════════════════════════
   RANGES
════════════════════════════════════════════════════════════════════════ */
.dash-range-group {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.dash-range-item {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.dash-range-item label {
    font-size: .875rem;
    color: var(--dash-text);
    font-weight: 500;
}

.dash-range-item small {
    font-size: .78rem;
    color: var(--dash-muted);
}

input[type="range"] {
    width: 100%;
    accent-color: var(--dash-accent);
    cursor: pointer;
    /* Track customizado via background para funcionar em modo escuro */
    -webkit-appearance: none;
    appearance: none;
    height: 4px;
    border-radius: 4px;
    background: linear-gradient(
        to right,
        var(--dash-accent) 0%,
        var(--dash-accent) var(--val-pct, 0%),
        var(--dash-track-empty, rgba(0,0,0,.15)) var(--val-pct, 0%),
        var(--dash-track-empty, rgba(0,0,0,.15)) 100%
    );
    outline: none;
}

/* Thumb WebKit */
input[type="range"]::-webkit-slider-thumb {
    -webkit-appearance: none;
    appearance: none;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: var(--dash-accent);
    cursor: pointer;
    border: 2px solid #fff;
    box-shadow: 0 1px 4px rgba(0,0,0,.25);
    transition: transform .15s, box-shadow .15s;
    margin-top: -6px;
}

input[type="range"]::-webkit-slider-thumb:hover {
    transform: scale(1.15);
    box-shadow: 0 2px 8px rgba(0,192,96,.4);
}

/* Thumb Firefox */
input[type="range"]::-moz-range-thumb {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: var(--dash-accent);
    cursor: pointer;
    border: 2px solid #fff;
    box-shadow: 0 1px 4px rgba(0,0,0,.25);
}

/* Track WebKit */
input[type="range"]::-webkit-slider-runnable-track {
    height: 4px;
    border-radius: 4px;
    background: transparent;
}

/* Track Firefox */
input[type="range"]::-moz-range-track {
    height: 4px;
    border-radius: 4px;
    background: linear-gradient(
        to right,
        var(--dash-accent) 0%,
        var(--dash-accent) var(--val-pct, 0%),
        var(--dash-track-empty, rgba(0,0,0,.15)) var(--val-pct, 0%),
        var(--dash-track-empty, rgba(0,0,0,.15)) 100%
    );
}

/* Modo escuro: track vazio mais visível */
[data-theme="escuro"] input[type="range"] {
    --dash-track-empty: rgba(255,255,255,.18);
}

.dash-probs-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 16px;
}

.dash-prob-item { display: flex; flex-direction: column; gap: 6px; }
.dash-prob-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.dash-prob-header label {
    font-size: .8125rem;
    color: var(--dash-text);
    font-weight: 500;
}
.dash-prob-header strong {
    font-size: .8125rem;
    color: var(--dash-accent-dk);
    min-width: 36px;
    text-align: right;
}

/* ════════════════════════════════════════════════════════════════════════
   PROMPT
════════════════════════════════════════════════════════════════════════ */
.dash-prompt-templates {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 14px;
}

.dash-prompt-templates__label {
    font-size: .78rem;
    color: var(--dash-muted);
    font-weight: 600;
}

.dash-tpl-btn {
    background: var(--dash-accent-dim);
    border: 1px solid rgba(0,192,96,.2);
    color: var(--dash-accent-dk);
    font-size: .78rem;
    font-weight: 600;
    padding: 5px 12px;
    border-radius: 20px;
    cursor: pointer;
    transition: background .15s, transform .12s;
}

.dash-tpl-btn:hover {
    background: var(--dash-accent);
    color: #fff;
    transform: translateY(-1px);
}

.dash-textarea-wrap {
    position: relative;
}

.dash-textarea {
    width: 100%;
    padding: 14px;
    border: 1px solid var(--dash-border);
    border-radius: 10px;
    font-size: .875rem;
    color: var(--dash-text);
    background: var(--dash-bg);
    resize: vertical;
    font-family: inherit;
    line-height: 1.6;
    box-sizing: border-box;
    transition: border-color .18s, box-shadow .18s;
}

.dash-textarea:focus {
    outline: none;
    border-color: var(--dash-accent);
    box-shadow: 0 0 0 3px rgba(0,192,96,.12);
}

.dash-textarea-counter {
    text-align: right;
    font-size: .72rem;
    color: var(--dash-muted);
    margin-top: 6px;
}

/* ════════════════════════════════════════════════════════════════════════
   MÍDIA
════════════════════════════════════════════════════════════════════════ */
.dash-form--inline .dash-form-row {
    display: grid;
    grid-template-columns: 1fr 1fr auto auto auto;
    gap: 12px;
    align-items: end;
}

.dash-form-field { display: flex; flex-direction: column; gap: 6px; }
.dash-form-field label { font-size: .8125rem; font-weight: 600; color: var(--dash-text); }
.dash-form-field--btn { justify-content: flex-end; }

.dash-input-file {
    font-size: .8125rem;
    color: var(--dash-text);
    padding: 8px 12px;
    border: 1px solid var(--dash-border);
    border-radius: 9px;
    background: var(--dash-bg);
    cursor: pointer;
}

.dash-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    padding: 48px;
    color: var(--dash-muted);
}

.dash-empty i { font-size: 3rem; opacity: .3; }

.dash-midias-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 16px;
}

.dash-midia-card {
    border: 1px solid var(--dash-border);
    border-radius: 12px;
    overflow: hidden;
    background: var(--dash-surface);
    position: relative;
    transition: box-shadow .18s, transform .18s;
}

.dash-midia-card:hover {
    box-shadow: var(--dash-shadow-md);
    transform: translateY(-2px);
}

.dash-midia-card__preview {
    width: 100%;
    aspect-ratio: 1;
    position: relative;
    background: var(--dash-bg);
    overflow: hidden;
}

.dash-midia-card__preview img,
.dash-midia-card__preview video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.dash-midia-card__type {
    position: absolute;
    top: 8px;
    left: 8px;
    font-size: 1rem;
}

.dash-midia-card__play {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 2.5rem;
    text-shadow: 0 2px 6px rgba(0,0,0,.4);
}

.dash-midia-card__info {
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.dash-midia-card__gatilho {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: .8125rem;
    font-weight: 700;
    color: var(--dash-accent-dk);
    background: var(--dash-accent-dim);
    border-radius: 6px;
    padding: 3px 8px;
    width: fit-content;
}

.dash-midia-card__desc {
    font-size: .75rem;
    color: var(--dash-muted);
}

.dash-midia-card__date {
    font-size: .72rem;
    color: var(--dash-muted);
}

.dash-midia-card__audio-preview {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 16px;
    background: var(--dash-accent-dim);
    box-sizing: border-box;
}

.dash-midia-card__audio-preview i {
    font-size: 2.5rem;
    color: var(--dash-accent);
}

.dash-midia-card__badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: .7rem;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 20px;
    width: fit-content;
}

.dash-midia-card__badge--direto {
    background: rgba(245,158,11,.1);
    color: #f59e0b;
    border: 1px solid rgba(245,158,11,.3);
}

.dash-midia-card__badge--prompt {
    background: rgba(59,130,246,.1);
    color: #3b82f6;
    border: 1px solid rgba(59,130,246,.25);
}

/* Info box gatilho prompt */
.dash-gatilho-info {
    margin-top: 16px;
    background: rgba(59,130,246,.07);
    border: 1px solid rgba(59,130,246,.25);
    border-radius: 10px;
    padding: 16px 20px;
    font-size: .875rem;
}

.dash-gatilho-info__header {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
    color: #3b82f6;
    margin-bottom: 10px;
    font-size: .9rem;
}

.dash-gatilho-info__header i {
    font-size: 1.1rem;
}

.dash-gatilho-info p {
    margin: 0 0 10px;
    color: var(--dash-text);
    line-height: 1.5;
}

.dash-gatilho-info__code {
    background: #1e293b;
    color: #e2e8f0;
    border-radius: 8px;
    padding: 12px 16px;
    font-size: .8rem;
    font-family: 'Courier New', monospace;
    line-height: 1.6;
    white-space: pre-wrap;
    word-break: break-word;
    margin: 0 0 10px;
    overflow-x: auto;
}

.dash-gatilho-info__hint {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: .8rem;
    color: #3b82f6;
    margin: 0 !important;
}

.dash-gatilho-info__hint code {
    background: rgba(59,130,246,.12);
    padding: 1px 6px;
    border-radius: 4px;
    font-size: .78rem;
}

/* ════════════════════════════════════════════════════════════════════════
   CONFIGURAÇÕES
════════════════════════════════════════════════════════════════════════ */
.dash-config-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 20px;
}

.dash-config-item {
    display: flex;
    flex-direction: column;
    gap: 8px;
    justify-content: space-between;
}

.dash-config-item label {
    font-size: .875rem;
    font-weight: 600;
    color: var(--dash-text);
}

.dash-config-item small {
    font-size: .78rem;
    color: var(--dash-muted);
}

.dash-config-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.dash-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.dash-field label {
    font-size: .8125rem;
    font-weight: 600;
    color: var(--dash-text);
}

.dash-field--full { grid-column: 1 / -1; }

/* ════════════════════════════════════════════════════════════════════════
   FORM ELEMENTS
════════════════════════════════════════════════════════════════════════ */
.dash-input,
.dash-select {
    padding: 10px 14px;
    border: 1px solid var(--dash-border);
    border-radius: 9px;
    font-size: .875rem;
    color: var(--dash-text);
    background: var(--dash-bg);
    font-family: inherit;
    transition: border-color .18s, box-shadow .18s;
    width: 100%;
    box-sizing: border-box;
}

.dash-input:focus,
.dash-select:focus {
    outline: none;
    border-color: var(--dash-accent);
    box-shadow: 0 0 0 3px rgba(0,192,96,.12);
}

.dash-inline-group {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}

.dash-inline-group .dash-input { flex: 1; min-width: 200px; }

/* ════════════════════════════════════════════════════════════════════════
   BOTÕES
════════════════════════════════════════════════════════════════════════ */
.btn-dash {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 10px;
    font-size: .875rem;
    font-weight: 600;
    font-family: inherit;
    border: none;
    cursor: pointer;
    transition: background .18s, transform .12s, box-shadow .18s;
    text-decoration: none;
    white-space: nowrap;
}

.btn-dash:active { transform: scale(.97); }

.btn-dash--primary {
    background: var(--dash-accent);
    color: #fff;
    box-shadow: 0 2px 8px rgba(0,192,96,.3);
}

.btn-dash--primary:hover {
    background: var(--dash-accent-dk);
    box-shadow: 0 4px 14px rgba(0,192,96,.4);
}

.btn-dash--ghost {
    background: var(--dash-accent-dim);
    color: var(--dash-accent-dk);
    border: 1px solid rgba(0,192,96,.2);
}

.btn-dash--ghost:hover {
    background: var(--dash-accent);
    color: #fff;
}

.btn-dash--danger {
    background: rgba(229,62,62,.08);
    color: var(--color-danger);
    border: 1px solid rgba(229,62,62,.25);
}

.btn-dash--danger:hover {
    background: var(--color-danger);
    color: #fff;
}

.btn-dash--lg {
    padding: 13px 28px;
    font-size: .9375rem;
}

.btn-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    font-size: 1rem;
    transition: background .15s, color .15s;
}

.btn-icon--danger {
    background: rgba(229,62,62,.07);
    color: var(--color-danger);
    border: 1px solid rgba(229,62,62,.25);
}

.btn-icon--danger:hover {
    background: var(--color-danger);
    color: #fff;
}

/* ════════════════════════════════════════════════════════════════════════
   FORM FOOTER
════════════════════════════════════════════════════════════════════════ */
.dash-form__footer {
    display: flex;
    justify-content: flex-end;
    padding-top: 8px;
}

/* ════════════════════════════════════════════════════════════════════════
   RESPONSIVE
════════════════════════════════════════════════════════════════════════ */
@media (max-width: 900px) {
    .wa-connect-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .dash-sidebar {
        position: fixed;
        left: -260px;
        top: 0;
        height: 100vh;
        z-index: 1100; /* acima do header fixo (z-index: 1000) e do mobile-menu (999) */
        transition: left .25s cubic-bezier(0.22,1,0.36,1);
        box-shadow: var(--dash-shadow-md);
    }

    .dash-sidebar.open {
        left: 0;
    }

    /* Overlay escurecido atrás do sidebar quando aberto */
    .dash-sidebar-backdrop {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.45);
        z-index: 1099;
        animation: backdropFadeIn .25s ease both;
    }

    .dash-sidebar-backdrop.active {
        display: block;
    }

    @keyframes backdropFadeIn {
        from { opacity: 0; }
        to   { opacity: 1; }
    }

    .dash-topbar {
        display: flex;
    }

    .dash-section {
        padding: 20px;
    }

    .dash-range-group,
    .dash-config-form-grid {
        grid-template-columns: 1fr;
    }

    .dash-form--inline .dash-form-row {
        grid-template-columns: 1fr;
    }

    .dash-alert {
        margin: 16px 20px 0;
    }
}

@media (max-width: 480px) {
    .dash-toggles-grid {
        grid-template-columns: 1fr;
    }

    .dash-probs-grid {
        grid-template-columns: 1fr 1fr;
    }
}

@keyframes fadeUp {
    from { opacity: 0; transform: translateY(16px); }
    to   { opacity: 1; transform: translateY(0); }
}

.dash-section { animation: fadeUp .4s cubic-bezier(0.22,1,0.36,1) both; }

/* ════════════════════════════════════════════════════════════════════════
   FREE PLAN RESTRICTIONS UI
════════════════════════════════════════════════════════════════════════ */

/* Banner topo — plano free */
.dash-free-banner {
    display: flex;
    align-items: center;
    gap: 14px;
    margin: 20px 32px 0;
    padding: 14px 20px;
    background: rgba(245,158,11,.08);
    border: 1px solid rgba(245,158,11,.3);
    border-radius: var(--dash-radius);
    animation: fadeUp .4s ease both;
}

.dash-free-banner__icon {
    width: 40px;
    height: 40px;
    background: #f59e0b;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.2rem;
    flex-shrink: 0;
}

.dash-free-banner__content {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.dash-free-banner__content strong {
    font-size: .875rem;
    font-weight: 700;
    color: var(--dash-text);
}

.dash-free-banner__content span {
    font-size: .8125rem;
    color: var(--dash-muted);
    line-height: 1.4;
}

.dash-free-banner__cta {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #f59e0b;
    color: #fff;
    font-size: .8125rem;
    font-weight: 700;
    padding: 8px 16px;
    border-radius: 8px;
    text-decoration: none;
    white-space: nowrap;
    transition: background .18s, transform .12s;
    flex-shrink: 0;
}

.dash-free-banner__cta:hover {
    background: #d97706;
    transform: translateY(-1px);
}

/* Badge no header dos cards */
.dash-free-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: rgba(245,158,11,.1);
    color: #d97706;
    border: 1px solid rgba(245,158,11,.3);
    font-size: .7rem;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 20px;
    margin-left: auto;
}

.dash-free-badge i { font-size: .8rem; }

/* Notice box dentro dos cards */
.dash-free-notice {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    background: rgba(245,158,11,.08);
    border: 1px solid rgba(245,158,11,.3);
    border-radius: 10px;
    padding: 12px 16px;
    font-size: .8125rem;
    color: var(--dash-text);
    line-height: 1.5;
    margin-bottom: 16px;
}

.dash-free-notice i {
    font-size: 1rem;
    color: #f59e0b;
    flex-shrink: 0;
    margin-top: 1px;
}

.dash-free-notice a {
    color: #d97706;
    font-weight: 700;
    text-decoration: underline;
}

.dash-free-notice--compact {
    padding: 10px 14px;
    margin-bottom: 20px;
}

/* Toggle bloqueado (plano free) */
.dash-toggle--locked {
    cursor: not-allowed;
    opacity: .65;
    background: var(--dash-surface);
    border-color: var(--dash-border);
    position: relative;
}

.dash-toggle--locked::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 10px;
    background: repeating-linear-gradient(
        45deg,
        transparent,
        transparent 4px,
        rgba(0,0,0,.02) 4px,
        rgba(0,0,0,.02) 8px
    );
    pointer-events: none;
}

.dash-toggle__slider--locked {
    background: #d1d5db !important;
}

.dash-toggle__slider--locked::after {
    background: #9ca3af;
}

/* Ícone de cadeado + badge Premium inline */
.dash-lock-icon {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: .68rem;
    font-weight: 700;
    color: #d97706;
    background: #fffbeb;
    border: 1px solid #fcd34d;
    border-radius: 12px;
    padding: 1px 7px;
    margin-left: 6px;
    vertical-align: middle;
    line-height: 1.6;
}

.dash-lock-icon i { font-size: .75rem; }

/* Contador do textarea com aviso free */
.dash-textarea-counter__free {
    color: #d97706;
    font-weight: 500;
}

.dash-textarea-counter__free a {
    color: #d97706;
    font-weight: 700;
}

/* Select option desabilitada (visual hint) */
select option:disabled {
    color: #9ca3af;
    background: #f9fafb;
}

/* Range limit hint (plano free) */
.dash-range-limit-hint {
    display: block;
    font-size: .72rem;
    color: #d97706;
    font-weight: 600;
    margin-top: 4px;
    letter-spacing: .01em;
}

/* Pulse ao bater no limite */
@keyframes rangePulse {
    0%   { accent-color: var(--dash-accent); }
    40%  { accent-color: #f59e0b; }
    100% { accent-color: var(--dash-accent); }
}

input[type="range"].range-at-limit {
    animation: rangePulse 0.6s ease;
}

/* Gradiente no track do range para indicar zona bloqueada no plano */
input[type="range"][data-plan-max],
input[type="range"][data-free-max] {
    background: linear-gradient(
        to right,
        var(--dash-accent) 0%,
        var(--dash-accent) var(--val-pct, 0%),
        var(--dash-track-empty, rgba(0,0,0,.15)) var(--val-pct, 0%),
        var(--dash-track-empty, rgba(0,0,0,.15)) calc(var(--free-pct, 50%) - 2px),
        #fcd34d calc(var(--free-pct, 50%) - 2px),
        #fcd34d calc(var(--free-pct, 50%) + 2px),
        rgba(0,0,0,.08) calc(var(--free-pct, 50%) + 2px),
        rgba(0,0,0,.08) 100%
    );
    border-radius: 4px;
    height: 4px;
    -webkit-appearance: none;
    appearance: none;
}

[data-theme="escuro"] input[type="range"][data-plan-max],
[data-theme="escuro"] input[type="range"][data-free-max] {
    background: linear-gradient(
        to right,
        var(--dash-accent) 0%,
        var(--dash-accent) var(--val-pct, 0%),
        rgba(255,255,255,.18) var(--val-pct, 0%),
        rgba(255,255,255,.18) calc(var(--free-pct, 50%) - 2px),
        #fcd34d calc(var(--free-pct, 50%) - 2px),
        #fcd34d calc(var(--free-pct, 50%) + 2px),
        rgba(255,255,255,.07) calc(var(--free-pct, 50%) + 2px),
        rgba(255,255,255,.07) 100%
    );
}

@media (max-width: 768px) {
    .dash-free-banner {
        margin: 16px 20px 0;
        flex-wrap: wrap;
    }
    .dash-free-banner__cta {
        width: 100%;
        justify-content: center;
    }
    .dash-free-notice {
        font-size: .78rem;
    }
    .dash-range-limit-hint {
        font-size: .7rem;
    }
}

/* ════════════════════════════════════════════════════════════════════════
   AUTO RESPOSTAS — Estilos completos
════════════════════════════════════════════════════════════════════════ */

/* ── Tipo grid ────────────────────────────────────────────────────────── */
.ar-tipo-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
    gap: 10px;
    margin-top: 8px;
}
.ar-tipo-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding: 12px 8px;
    background: var(--dash-bg);
    border: 1.5px solid var(--dash-border);
    border-radius: var(--dash-radius);
    cursor: pointer;
    font-size: .82rem;
    font-weight: 500;
    color: var(--dash-muted);
    transition: all .15s;
}
.ar-tipo-btn i { font-size: 1.4rem; }
.ar-tipo-btn.active {
    border-color: var(--dash-accent);
    background: var(--dash-accent-dim);
    color: var(--dash-accent-dk);
}
.ar-tipo-btn:hover:not(.active) {
    border-color: var(--dash-accent);
    color: var(--dash-text);
}

/* ── Grids ────────────────────────────────────────────────────────────── */
.ar-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.ar-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
@media (max-width: 680px) {
    .ar-grid-2, .ar-grid-3 { grid-template-columns: 1fr; }
    .ar-tipo-grid { grid-template-columns: repeat(3, 1fr); }
}

/* ── Painéis de payload ───────────────────────────────────────────────── */
.ar-payload-panels { margin-top: 18px; }
.ar-panel { display: none; }
.ar-panel.active { display: block; }

/* ── Textarea ─────────────────────────────────────────────────────────── */
.ar-textarea { min-height: 100px; resize: vertical; }

/* ── Reply Buttons ────────────────────────────────────────────────────── */
.ar-buttons-list { display: flex; flex-direction: column; gap: 10px; margin-top: 12px; }
.ar-btn-item {
    display: flex;
    align-items: center;
    gap: 10px;
}
.ar-btn-num {
    width: 24px; height: 24px;
    background: var(--dash-accent);
    color: #fff;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .75rem; font-weight: 700;
    flex-shrink: 0;
}
.ar-btn-input { flex: 2; }
.ar-btn-id-input { flex: 1; }

/* ── List Message ─────────────────────────────────────────────────────── */
.ar-list-section {
    background: var(--dash-bg);
    border: 1px solid var(--dash-border);
    border-radius: 10px;
    padding: 12px;
    margin-top: 10px;
}
.ar-list-section-header {
    display: flex; align-items: center; gap: 10px; margin-bottom: 10px;
}
.ar-list-section-header input { flex: 1; }
.ar-list-rows { display: flex; flex-direction: column; gap: 8px; }
.ar-list-row { display: flex; gap: 8px; align-items: center; }
.ar-add-row-btn {
    background: none;
    border: 1.5px dashed var(--dash-border);
    border-radius: 8px;
    padding: 6px 12px;
    color: var(--dash-muted);
    cursor: pointer;
    font-size: .82rem;
    margin-top: 8px;
    transition: all .15s;
    display: flex; align-items: center; gap: 6px;
}
.ar-add-row-btn:hover { border-color: var(--dash-accent); color: var(--dash-accent); }

/* ── Poll ─────────────────────────────────────────────────────────────── */
.ar-poll-option { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.ar-poll-option input { flex: 1; }
.ar-checkbox-label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: .9rem; }

/* ── Sequential ───────────────────────────────────────────────────────── */
.ar-seq-item {
    background: var(--dash-bg);
    border: 1px solid var(--dash-border);
    border-radius: 10px;
    padding: 12px;
    margin-bottom: 10px;
}
.ar-seq-header {
    display: flex; align-items: center; gap: 12px; margin-bottom: 10px;
}
.ar-seq-num { font-weight: 600; font-size: .85rem; color: var(--dash-accent-dk); flex: 1; }
.ar-seq-delay { display: flex; align-items: center; gap: 6px; font-size: .82rem; }
.ar-seq-text { width: 100%; box-sizing: border-box; }

/* ── Info box ─────────────────────────────────────────────────────────── */
.ar-info-box {
    display: flex; align-items: flex-start; gap: 10px;
    background: #eff6ff; border: 1px solid #bfdbfe;
    border-radius: 10px; padding: 12px 14px;
    color: #1e40af; font-size: .85rem;
    margin-bottom: 16px;
}
.ar-info-box i { font-size: 1.1rem; flex-shrink: 0; margin-top: 1px; }

/* ── Secondary button ─────────────────────────────────────────────────── */
.ar-secondary-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    background: var(--dash-bg);
    border: 1.5px solid var(--dash-border);
    border-radius: 8px;
    cursor: pointer;
    font-size: .85rem;
    color: var(--dash-text);
    transition: all .15s;
}
.ar-secondary-btn:hover { border-color: var(--dash-accent); color: var(--dash-accent-dk); }

/* ── Remove button ────────────────────────────────────────────────────── */
.ar-rm-btn {
    background: none; border: none; cursor: pointer;
    color: var(--color-danger); font-size: 1rem; padding: 4px;
    border-radius: 6px; transition: background .15s;
}
.ar-rm-btn:hover { background: #fee2e2; }

/* ── Preview JSON ─────────────────────────────────────────────────────── */
.ar-preview-box {
    background: #0f1f16;
    border-radius: 10px;
    margin-top: 16px;
    overflow: hidden;
}
.ar-preview-header {
    padding: 8px 16px;
    background: #1a2e20;
    color: #86efac;
    font-size: .82rem;
    font-weight: 600;
    display: flex; align-items: center; gap: 6px;
}
.ar-preview-json {
    color: #a7f3d0;
    font-size: .78rem;
    padding: 14px 16px;
    margin: 0;
    overflow-x: auto;
    line-height: 1.6;
}

/* ── Form footer ──────────────────────────────────────────────────────── */
.ar-form-footer { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }

/* ── Count badge ──────────────────────────────────────────────────────── */
.ar-count-badge {
    display: inline-flex; align-items: center; justify-content: center;
    background: var(--dash-accent); color: #fff;
    border-radius: 12px; font-size: .72rem; font-weight: 700;
    padding: 1px 8px; margin-left: 6px;
}

/* ── Empty state ──────────────────────────────────────────────────────── */
.ar-empty-state {
    text-align: center; padding: 48px 24px;
    color: var(--dash-muted);
}
.ar-empty-icon { font-size: 3rem; opacity: .3; display: block; margin-bottom: 12px; }
.ar-empty-state p { font-size: 1rem; margin: 0 0 6px; }
.ar-empty-state small { font-size: .85rem; }

/* ── Lista de respostas ───────────────────────────────────────────────── */
.ar-list { display: flex; flex-direction: column; gap: 12px; }

.ar-item {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 16px;
    background: var(--dash-bg);
    border: 1.5px solid var(--dash-border);
    border-radius: 12px;
    padding: 14px 16px;
    transition: border-color .15s;
}
.ar-item--ativo { border-left: 4px solid var(--dash-accent); }
.ar-item--pausado { border-left: 4px solid var(--dash-muted); opacity: .75; }
.ar-item__left { flex: 1; min-width: 0; }

.ar-item__tipo-badge {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: .75rem; font-weight: 600;
    background: var(--dash-accent-dim);
    color: var(--dash-accent-dk);
    border-radius: 6px;
    padding: 2px 8px;
    margin-bottom: 6px;
}
.ar-item__nome {
    font-weight: 700; font-size: .95rem; color: var(--dash-text);
    margin-bottom: 6px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ar-item__gatilhos { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.ar-gatilho-tag {
    background: var(--dash-surface);
    border: 1px solid var(--dash-border);
    border-radius: 20px;
    padding: 2px 10px;
    font-size: .75rem; color: var(--dash-muted);
    font-family: monospace;
}
.ar-item__meta {
    display: flex; flex-wrap: wrap; gap: 12px;
    font-size: .78rem; color: var(--dash-muted);
    align-items: center;
}
.ar-item__meta span { display: flex; align-items: center; gap: 4px; }
.ar-status-pill {
    display: inline-flex; align-items: center;
    border-radius: 20px; padding: 2px 10px;
    font-size: .72rem; font-weight: 700;
}
.ar-status-pill--on  { background: #d1fae5; color: #065f46; }
.ar-status-pill--off { background: #f1f5f9; color: #64748b; }

.ar-item__actions {
    display: flex; flex-direction: column; gap: 6px;
    flex-shrink: 0;
}
.ar-action-btn {
    width: 34px; height: 34px;
    border: 1.5px solid var(--dash-border);
    border-radius: 8px;
    background: var(--dash-surface);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem;
    transition: all .15s;
    color: var(--dash-muted);
}
.ar-action-btn:hover { border-color: var(--dash-accent); color: var(--dash-accent); }
.ar-action-btn--del:hover { border-color: var(--color-danger); color: var(--color-danger); background: #fee2e2; }
.ar-action-btn--toggle:hover { color: var(--dash-accent-dk); }

/* ── Modal ────────────────────────────────────────────────────────────── */
.ar-modal-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.5);
    z-index: 9999;
    display: flex; align-items: center; justify-content: center;
}
.ar-modal {
    background: var(--dash-surface);
    border-radius: 14px;
    width: min(600px, 92vw);
    max-height: 80vh;
    overflow: hidden;
    display: flex; flex-direction: column;
    box-shadow: var(--dash-shadow-md);
}
.ar-modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px;
    border-bottom: 1px solid var(--dash-border);
    font-weight: 600;
    font-size: .9rem;
}
.ar-modal-close {
    background: none; border: none; cursor: pointer;
    font-size: 1.1rem; color: var(--dash-muted);
    padding: 4px; border-radius: 6px;
}
.ar-modal-close:hover { background: var(--dash-bg); }
.ar-modal-json {
    background: #0f1f16;
    color: #a7f3d0;
    font-size: .8rem;
    padding: 18px;
    overflow: auto;
    flex: 1;
    margin: 0;
    line-height: 1.6;
}

/* ── Delay row ────────────────────────────────────────────────────────── */
.ar-delay-row { margin-bottom: 4px; }

/* ── Field hint ───────────────────────────────────────────────────────── */
.dash-field__hint { font-size: .78rem; color: var(--dash-muted); margin-top: 4px; display: block; }
</style>

<script>
// ── Plano Free: flags passados do PHP ────────────────────────────────────
const IS_FREE_PLAN = <?= $isFreePlan ? 'true' : 'false' ?>;

// Limites máximos globais (teto absoluto do plano)
const GLOBAL_LIMITS = <?= json_encode($globalLimits) ?>;

// ── handleFreeRange: cap slider no limite do plano ───────────────────────
function handleFreeRange(input, labelId, formatter) {
    // data-plan-max = limite efetivo do plano; data-free-max = compatibilidade
    var planMax = input.dataset.planMax !== undefined ? parseFloat(input.dataset.planMax)
                : (input.dataset.freeMax !== undefined ? parseFloat(input.dataset.freeMax) : null);
    var val = parseFloat(input.value);
    if (planMax !== null && val > planMax) {
        input.value = planMax;
        val = planMax;
        // Visual pulse para indicar o limite
        input.classList.add('range-at-limit');
        setTimeout(function() { input.classList.remove('range-at-limit'); }, 600);
    }
    var el = document.getElementById(labelId);
    if (el) el.textContent = formatter(input.value);
}

// ── Mídia: atualizarAceito() mantida por compatibilidade (nova UI usa selecionarTipoMidia) ──
function atualizarAceito() { /* substituída pela nova UI premium */ }

function toggleGatilhoInfo() {
    var sel  = document.getElementById('tipoGatilhoSelect');
    var info = document.getElementById('gatilhoPromptInfo');
    if (!sel || !info) return;
    info.style.display = sel.value === 'prompt' ? 'block' : 'none';
    // Atualiza exemplos com o gatilho digitado
    atualizarExemploGatilho();
}

function atualizarExemploGatilho() {
    var gatilhoInput = document.querySelector('input[name="gatilho"]');
    var spans = document.querySelectorAll('#gatilhoNomeExemplo, #gatilhoNomeExemplo2');
    if (!gatilhoInput || !spans.length) return;
    var val = gatilhoInput.value.trim() || 'nome_do_gatilho';
    spans.forEach(function(s) { s.textContent = val; });
}

// Atualiza exemplo ao digitar o gatilho
(function() {
    document.addEventListener('DOMContentLoaded', function() {
        var gi = document.querySelector('input[name="gatilho"]');
        if (gi) gi.addEventListener('input', atualizarExemploGatilho);
    });
})();

// ── Sidebar mobile toggle ────────────────────────────────────────────────
(function() {
    var btn = document.getElementById('sidebarToggle');
    var sb  = document.querySelector('.dash-sidebar');
    if (!btn || !sb) return;

    // Cria o backdrop dinamicamente
    var backdrop = document.createElement('div');
    backdrop.className = 'dash-sidebar-backdrop';
    document.body.appendChild(backdrop);

    function openSidebar() {
        sb.classList.add('open');
        backdrop.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        sb.classList.remove('open');
        backdrop.classList.remove('active');
        document.body.style.overflow = '';
    }

    btn.addEventListener('click', function() {
        sb.classList.contains('open') ? closeSidebar() : openSidebar();
    });

    backdrop.addEventListener('click', function() {
        closeSidebar();
    });

    document.addEventListener('click', function(e) {
        if (sb.classList.contains('open') && !sb.contains(e.target) && e.target !== btn && !btn.contains(e.target) && e.target !== backdrop) {
            closeSidebar();
        }
    });
})();

// ── Init: gradient track nos ranges com limite de plano ──────────────────
(function() {
    // Atualiza --val-pct (preenchimento do track) em TODOS os ranges
    function updateRangeTrack(input) {
        var min = parseFloat(input.min) || 0;
        var max = parseFloat(input.max) || 100;
        var val = parseFloat(input.value);
        var valPct = ((val - min) / (max - min) * 100).toFixed(2);
        input.style.setProperty('--val-pct', valPct + '%');
    }

    // Atualiza --free-pct (marcador do limite de plano) nos ranges com data-plan-max
    function updateFreePct(input) {
        var planMax = parseFloat(input.dataset.planMax || input.dataset.freeMax || input.max);
        var min = parseFloat(input.min) || 0;
        var max = parseFloat(input.max) || 100;
        var pct = ((planMax - min) / (max - min) * 100).toFixed(1);
        input.style.setProperty('--free-pct', pct + '%');
    }

    document.querySelectorAll('input[type="range"]').forEach(function(input) {
        updateRangeTrack(input);
        if (input.dataset.planMax !== undefined || input.dataset.freeMax !== undefined) {
            updateFreePct(input);
        }
        input.addEventListener('input', function() {
            updateRangeTrack(input);
        });
    });
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>