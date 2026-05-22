<?php
/**
 * dash-config.php — Wixy
 * Lógica PHP centralizada: queries, variáveis globais, handlers POST/AJAX
 */

require_once __DIR__ . '/config.php';

// Protege rota
if (empty($_SESSION['usuario_id'])) {
    redirect('/login');
}

$pdo = db();
$userId = (int) $_SESSION['usuario_id'];
$usuarioNome = $_SESSION['usuario_nome'] ?? 'Usuário';

// Sincroniza fuso horário
try { $pdo->exec("SET time_zone = '-03:00'"); } catch (PDOException $e) {}

// ════════════════════════════════════════════════════════════════════════
// DADOS DO USUÁRIO
// ════════════════════════════════════════════════════════════════════════
$stmtUsuario = $pdo->prepare("
    SELECT u.plano, u.criado_em, u.desativado, u.plano_expira,
           u.avatar_url, p.nome AS plano_nome
    FROM usuarios u
    LEFT JOIN planos_site p ON p.id = u.plano_id
    WHERE u.id = ? LIMIT 1
");
$stmtUsuario->execute([$userId]);
$usuarioDados = $stmtUsuario->fetch(PDO::FETCH_ASSOC)
    ?: ['plano' => 'free', 'criado_em' => date('Y-m-d H:i:s'), 'desativado' => 0, 'plano_expira' => null, 'plano_nome' => null];

// Config global
$trialDias = 3;
$bonusDiasChave = 10;
try {
    $r = $pdo->query("SELECT valor FROM bot_ia_config WHERE chave = 'trial_dias' LIMIT 1")->fetch();
    if ($r) $trialDias = (int)$r['valor'];
    $rb = $pdo->query("SELECT valor FROM bot_ia_config WHERE chave = 'bonus_dias_chave' LIMIT 1")->fetch();
    if ($rb) $bonusDiasChave = (int)$rb['valor'];
} catch (PDOException $e) {}

// ════════════════════════════════════════════════════════════════════════
// RESTRIÇÕES DO PLANO
// ════════════════════════════════════════════════════════════════════════
$isFreePlan = ($usuarioDados['plano'] === 'free');
$planoAtual = $usuarioDados['plano'] ?: 'free';

$planLimits = null;
try {
    $stmtLim = $pdo->prepare("SELECT * FROM plano_limites WHERE plano_slug = ? LIMIT 1");
    $stmtLim->execute([$planoAtual]);
    $planLimits = $stmtLim->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$planLimits && $planoAtual !== 'free') {
        $stmtLim->execute(['free']);
        $planLimits = $stmtLim->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (PDOException $e) { $planLimits = null; }

// Defaults absolutos
$limDefaults = [
    'max_sessoes' => 99,
    'delay_min_max' => 60,
    'delay_max_max' => 60,
    'contexto_msgs_max' => 20,
    'temperature_max' => 2.0,
    'max_tokens_max' => 250,
    'max_chars_max' => 1000,
    'max_tokens_por_prompt_max' => 50000,
    'humanizacao_basica' => '*',
    'humanizacao_avancada' => '*',
    'midia_tipos' => '*',
    'liberar_tudo' => 1,
    'exibir_banners' => 1,
    'global_max_tokens' => 250,
    'global_max_chars' => 1000,
    'global_delay_min_max' => 60,
    'global_delay_max_max' => 60,
    'global_contexto_max' => 20,
    'global_max_sessoes' => 99,
    'global_max_tokens_por_prompt' => 50000,
];

if ($planLimits) {
    $limDefaults = array_merge($limDefaults, $planLimits);
}

$limTudo = !empty($limDefaults['liberar_tudo']);
$exibirBanners = isset($limDefaults['exibir_banners']) ? (bool)$limDefaults['exibir_banners'] : true;

// Limites globais
$globalLimits = [
    'max_tokens' => (int)($limDefaults['global_max_tokens'] ?? 250),
    'max_tokens_por_prompt' => (int)($limDefaults['global_max_tokens_por_prompt'] ?? 4096),
    'max_chars' => (int)($limDefaults['global_max_chars'] ?? 1000),
    'delay_min' => (int)($limDefaults['global_delay_min_max'] ?? 60),
    'delay_max' => (int)($limDefaults['global_delay_max_max'] ?? 60),
    'contexto_msgs' => (int)($limDefaults['global_contexto_max'] ?? 20),
    'max_sessoes' => (int)($limDefaults['global_max_sessoes'] ?? 10),
];

// Limites efetivos
$freeLimits = [
    'contexto_msgs' => min($globalLimits['contexto_msgs'], $limTudo ? $globalLimits['contexto_msgs'] : (int)$limDefaults['contexto_msgs_max']),
    'delay_min' => min($globalLimits['delay_min'], $limTudo ? $globalLimits['delay_min'] : (int)$limDefaults['delay_min_max']),
    'delay_max' => min($globalLimits['delay_max'], $limTudo ? $globalLimits['delay_max'] : (int)$limDefaults['delay_max_max']),
    'temperature' => $limTudo ? 2.0 : (float)$limDefaults['temperature_max'],
    'max_tokens' => min($globalLimits['max_tokens'], $limTudo ? $globalLimits['max_tokens'] : (int)$limDefaults['max_tokens_max']),
    'max_tokens_por_prompt' => min($globalLimits['max_tokens_por_prompt'], $limTudo ? $globalLimits['max_tokens_por_prompt'] : (int)$limDefaults['max_tokens_por_prompt_max']),
    'max_chars' => min($globalLimits['max_chars'], $limTudo ? $globalLimits['max_chars'] : (int)$limDefaults['max_chars_max']),
    'system_prompt' => $limTudo ? $globalLimits['max_tokens_por_prompt'] : (int)$limDefaults['max_tokens_por_prompt_max'],
    'max_sessoes' => min($globalLimits['max_sessoes'], $limTudo ? $globalLimits['max_sessoes'] : (int)$limDefaults['max_sessoes']),
];

// Toggles bloqueados
$togBasicoTodos = ['humanizar_erros','humanizar_abrev','humanizar_reticencias','humanizar_emoji','humanizar_minusc','humanizar_pontuacao'];
$togAvancTodos = ['humanizar_girias','humanizar_hesitacao','humanizar_risada','humanizar_fragmentar','humanizar_delay_extra','humanizar_emoji_reacao','humanizar_repetir_palavra','humanizar_ne_final'];

if ($limTudo) {
    $hBasicaPermitidos = $togBasicoTodos;
    $hAvancPermitidos = $togAvancTodos;
} else {
    $hbRaw = $limDefaults['humanizacao_basica'] ?? '[]';
    $hBasicaPermitidos = ($hbRaw === '*') ? $togBasicoTodos : (json_decode($hbRaw, true) ?: []);
    $haRaw = $limDefaults['humanizacao_avancada'] ?? '[]';
    $hAvancPermitidos = ($haRaw === '*') ? $togAvancTodos : (json_decode($haRaw, true) ?: []);
}

$freeBlockedToggles = array_values(array_diff(
    array_merge($togBasicoTodos, $togAvancTodos),
    array_merge($hBasicaPermitidos, $hAvancPermitidos)
));

// Tipos de mídia
if ($limTudo) {
    $midiaPermitidos = ['imagem','video','audio'];
} else {
    $midiaRaw = $limDefaults['midia_tipos'] ?? '["imagem"]';
    $midiaPermitidos = ($midiaRaw === '*') ? ['imagem','video','audio'] : (json_decode($midiaRaw, true) ?: ['imagem']);
}
$freeBlockedMidiaTypes = array_values(array_diff(['imagem','video','audio'], $midiaPermitidos));

// ════════════════════════════════════════════════════════════════════════
// CRIAR TABELAS
// ════════════════════════════════════════════════════════════════════════
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
    try { $pdo->exec("ALTER TABLE bot_whatsapp_sessoes DROP INDEX usuario_id"); } catch(PDOException $e){}
    try { $pdo->exec("ALTER TABLE bot_whatsapp_sessoes DROP INDEX uq_usuario_id"); } catch(PDOException $e){}
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

// ════════════════════════════════════════════════════════════════════════
// CALCULAR STATUS DO TRIAL
// ════════════════════════════════════════════════════════════════════════
$diasDesde = (int) floor((time() - strtotime($usuarioDados['criado_em'])) / 86400);
$trialExpiraEm = date('d/m/Y', strtotime($usuarioDados['criado_em']) + $trialDias * 86400);

$iaTrialExpirado = false;
if (!empty($usuarioDados['desativado'])) {
    $iaTrialExpirado = true;
} elseif ($usuarioDados['plano'] === 'free') {
    $iaTrialExpirado = ($diasDesde > $trialDias);
}

// Monta labels sidebar
if (!empty($usuarioDados['desativado'])) {
    $sidebarPlanoLabel = 'Conta desativada';
    $sidebarPlanoExpira = null;
    $sidebarPlanoClass = 'expirado';
} elseif ($usuarioDados['plano'] === 'free') {
    $sidebarPlanoLabel = 'Plano Grátis';
    $sidebarPlanoExpira = $iaTrialExpirado ? 'Trial encerrado' : 'Vence em ' . $trialExpiraEm;
    $sidebarPlanoClass = $iaTrialExpirado ? 'expirado' : 'trial';
} else {
    $sidebarPlanoLabel = $usuarioDados['plano_nome'] ?? 'Plano Pago';
    if (!empty($usuarioDados['plano_expira'])) {
        $sidebarPlanoExpira = 'Vence em ' . date('d/m/Y', strtotime($usuarioDados['plano_expira']));
        $sidebarPlanoClass = strtotime($usuarioDados['plano_expira']) < time() ? 'expirado' : 'pago';
    } else {
        $sidebarPlanoExpira = 'Acesso vitalício';
        $sidebarPlanoClass = 'pago';
    }
}

// ════════════════════════════════════════════════════════════════════════
// CARREGAR CONFIGS DO USUÁRIO
// ════════════════════════════════════════════════════════════════════════
$configDefaults = [
    'humanizar_erros' => '1',
    'humanizar_abrev' => '1',
    'humanizar_reticencias' => '1',
    'humanizar_emoji' => '1',
    'humanizar_minusc' => '1',
    'humanizar_pontuacao' => '1',
    'humanizar_girias' => '1',
    'humanizar_hesitacao' => '1',
    'humanizar_risada' => '1',
    'humanizar_fragmentar' => '0',
    'humanizar_delay_extra' => '0',
    'humanizar_emoji_reacao' => '1',
    'humanizar_repetir_palavra' => '0',
    'humanizar_ne_final' => '1',
    'prob_minusc' => '35',
    'prob_pontuacao' => '50',
    'prob_abrev' => '25',
    'prob_reticencias' => '20',
    'prob_erros' => '15',
    'prob_girias' => '20',
    'prob_hesitacao' => '20',
    'prob_risada' => '60',
    'prob_caps' => '15',
    'prob_emoji_reacao' => '25',
    'prob_ne_final' => '20',
    'delay_min' => '4',
    'delay_max' => '14',
    'temperature' => '1.1',
    'max_tokens' => '80',
    'max_chars' => '300',
    'system_prompt' => "Você é um atendente brasileiro extremamente humano...",
    'contexto_msgs' => '15',
    'fallback_msg' => 'oi! to aqui 😊',
    'fallback_ativo' => '1',
    'blacklist' => '',
    'idioma' => 'pt-BR',
    'modo_resposta' => 'natural',
    'horario_inicio' => '00:00',
    'horario_fim' => '23:59',
    'fora_horario_msg' => '',
    'ia_ativo' => '1',
    'ar_ativo' => '1',
];

$stmtIns = $pdo->prepare("INSERT IGNORE INTO bot_ia_config_usuario (usuario_id, chave, valor) VALUES (?, ?, ?)");
foreach ($configDefaults as $k => $v) {
    $stmtIns->execute([$userId, $k, $v]);
}

$stmtCfg = $pdo->prepare("SELECT chave, valor FROM bot_ia_config_usuario WHERE usuario_id = ?");
$stmtCfg->execute([$userId]);
$cfg = [];
foreach ($stmtCfg->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $cfg[$row['chave']] = $row['valor'];
}
$cfg = array_merge($configDefaults, $cfg);

// ════════════════════════════════════════════════════════════════════════
// USAR STATS E DADOS
// ════════════════════════════════════════════════════════════════════════
$usageStats = ['hoje' => ['ia' => 0, 'ar' => 0], 'ontem' => ['ia' => 0, 'ar' => 0]];
$limitesMsgs = ['ia' => 50, 'ar' => 200];
try {
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
        if ($rowUsage['ultimo_reset'] !== $hoje) {
            $pdo->prepare("INSERT INTO bot_usage_stats (usuario_id, msgs_ia_hoje, msgs_ar_hoje, msgs_ia_ontem, msgs_ar_ontem, ultimo_reset)
                           VALUES (?, 0, 0, ?, ?, CURRENT_DATE)
                           ON DUPLICATE KEY UPDATE msgs_ia_ontem = msgs_ia_hoje, msgs_ar_ontem = msgs_ar_hoje, msgs_ia_hoje = 0, msgs_ar_hoje = 0, ultimo_reset = CURRENT_DATE")
                ->execute([$userId, $rowUsage['msgs_ia_hoje'], $rowUsage['msgs_ar_hoje']]);
            $usageStats['hoje'] = ['ia' => 0, 'ar' => 0];
            $usageStats['ontem'] = ['ia' => (int)$rowUsage['msgs_ia_hoje'], 'ar' => (int)$rowUsage['msgs_ar_hoje']];
        } else {
            $usageStats['hoje'] = ['ia' => (int)$rowUsage['msgs_ia_hoje'], 'ar' => (int)$rowUsage['msgs_ar_hoje']];
            $usageStats['ontem'] = ['ia' => (int)$rowUsage['msgs_ia_ontem'], 'ar' => (int)$rowUsage['msgs_ar_ontem']];
        }
    }
} catch (PDOException $e) {}

// ════════════════════════════════════════════════════════════════════════
// SESSÕES WHATSAPP
// ════════════════════════════════════════════════════════════════════════
$maxSessoesPlano = !empty($usuarioDados['desativado']) ? 0 : $freeLimits['max_sessoes'];

$stmtSessoes = $pdo->prepare("SELECT * FROM bot_whatsapp_sessoes WHERE usuario_id = ? ORDER BY criado_em ASC");
$stmtSessoes->execute([$userId]);
$sessoesWA = $stmtSessoes->fetchAll(PDO::FETCH_ASSOC);

if (empty($sessoesWA)) {
    $sessionId = 'wixy_' . $userId . '_' . bin2hex(random_bytes(8));
    $pdo->prepare("INSERT INTO bot_whatsapp_sessoes (usuario_id, session_id, status, apelido) VALUES (?,?,?,?)")
        ->execute([$userId, $sessionId, 'desconectado', 'Sessão 1']);
    $stmtSessoes->execute([$userId]);
    $sessoesWA = $stmtSessoes->fetchAll(PDO::FETCH_ASSOC);
}

$sessaoWA = $sessoesWA[0] ?? null;

// ════════════════════════════════════════════════════════════════════════
// MÍDIAS E AUTO RESPOSTAS
// ════════════════════════════════════════════════════════════════════════
$stmtMidias = $pdo->prepare("SELECT * FROM bot_ia_midias_usuario WHERE usuario_id = ? ORDER BY criado_em DESC");
$stmtMidias->execute([$userId]);
$midias = $stmtMidias->fetchAll(PDO::FETCH_ASSOC);

$stmtAR = $pdo->prepare("SELECT * FROM bot_auto_respostas WHERE usuario_id = ? ORDER BY ordem ASC, criado_em DESC");
$stmtAR->execute([$userId]);
$autoRespostas = $stmtAR->fetchAll(PDO::FETCH_ASSOC);

// ════════════════════════════════════════════════════════════════════════
// GROQ KEYS
// ════════════════════════════════════════════════════════════════════════
$groqKeysUsuario = [];
try {
    $stmtGK = $pdo->prepare("SELECT * FROM usuario_groq_keys WHERE usuario_id = ? ORDER BY criado_em DESC");
    $stmtGK->execute([$userId]);
    $groqKeysUsuario = $stmtGK->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$totalDiasGanhos = 0;
foreach ($groqKeysUsuario as $gk) {
    $totalDiasGanhos += (int)$gk['dias_ganhos'];
}

// ════════════════════════════════════════════════════════════════════════
// ABA ATIVA
// ════════════════════════════════════════════════════════════════════════
$aba = $_GET['aba'] ?? 'whatsapp';
$abasValidas = ['whatsapp','humanizacao','prompt','midia','auto_respostas','config','groq_key'];
if (!in_array($aba, $abasValidas)) $aba = 'whatsapp';

$titulos = [
    'whatsapp' => 'WhatsApp',
    'humanizacao' => 'Humanização',
    'prompt' => 'Prompt',
    'midia' => 'Mídia IA',
    'auto_respostas' => 'Auto Respostas',
    'groq_key' => 'Ganhar Dias',
    'config' => 'Configurações',
];

// ════════════════════════════════════════════════════════════════════════
// MENSAGENS
// ════════════════════════════════════════════════════════════════════════
$salvoMsg = '';
$salvoErro = '';

// HANDLERS POST/AJAX vêm aqui (continuação em arquivo separado se necessário)
// Por enquanto deixamos vazios — cada aba terá seu arquivo de handlers
?>
