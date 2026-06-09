<?php

ini_set('display_errors', '0');
set_time_limit(120);

require_once __DIR__ . '/api.php';

header('Content-Type: application/json; charset=UTF-8');

function responderJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalizarDoencaSolicitada(string $doenca): string
{
    $mapa = [
        'dengue' => 'dengue',
        'zika' => 'zikavirus',
        'zikavirus' => 'zikavirus',
        'chikungunya' => 'chikungunya',
    ];

    return $mapa[strtolower(trim($doenca))] ?? '';
}

$action = trim((string) ($_GET['action'] ?? 'estados'));
$uf = nomeEstadoParaSigla((string) ($_GET['uf'] ?? ''));
$cidade = trim((string) ($_GET['cidade'] ?? ''));
$doenca = trim((string) ($_GET['doenca'] ?? 'dengue'));
$acoesPermitidas = [
    'estados',
    'cidades',
    'resultado',
    'estabelecimentos',
    'hospitais',
    'ubs',
    'arboviroses',
    'mais-medicos',
];

if (!in_array($action, $acoesPermitidas, true)) {
    responderJson(['erro' => 'Ação inválida.'], 400);
}

if ($action === 'estados') {
    responderJson([
        'erro' => '',
        'estados' => obterEstadosFixos(),
    ]);
}

if ($uf === '') {
    responderJson(['erro' => 'UF não informada.'], 400);
}

$dados = obterEstadosECidades($uf);

if ($dados['erro'] !== '') {
    responderJson([
        'erro' => $dados['erro'],
        'debug' => $dados['debug'] ?? null,
    ], 502);
}

if ($action === 'cidades') {
    responderJson([
        'erro' => '',
        'uf' => $uf,
        'cidades' => $dados['cidadesPorEstado'][$uf] ?? [],
    ]);
}

if ($cidade === '') {
    responderJson(['erro' => 'Cidade não informada.'], 400);
}

$registroEncontrado = obterRegistroMunicipio($dados['registros'], $uf, $cidade);

if ($registroEncontrado === null) {
    responderJson([
        'erro' => 'Cidade não encontrada para a UF informada.',
        'uf' => $uf,
        'cidade' => $cidade,
    ], 404);
}

$codigoMunicipio = (string) ($registroEncontrado['codigo_municipio'] ?? '');
$municipio = (string) ($registroEncontrado['municipio'] ?? $cidade);

switch ($action) {
    case 'resultado':
        responderJson([
            'erro' => '',
            'resultado' => $registroEncontrado,
        ]);
        break;

    case 'estabelecimentos':
        $itens = obterEstabelecimentosPorMunicipio($codigoMunicipio, $uf, $municipio);
        if (isset($itens['erro'])) {
            responderJson([
                'erro' => 'Falha ao buscar estabelecimentos de saúde.',
            ], 502);
        }

        responderJson([
            'erro' => '',
            'uf' => $uf,
            'cidade' => $municipio,
            'codigo_municipio' => $codigoMunicipio,
            'total' => count($itens),
            'itens' => $itens,
        ]);
        break;

    case 'hospitais':
        $itens = obterHospitaisPorMunicipio($uf, $municipio, $codigoMunicipio);
        if (isset($itens['erro'])) {
            responderJson([
                'erro' => 'Falha ao buscar hospitais e leitos.',
            ], 502);
        }

        responderJson([
            'erro' => '',
            'uf' => $uf,
            'cidade' => $municipio,
            'codigo_municipio' => $codigoMunicipio,
            'total' => count($itens),
            'itens' => $itens,
        ]);
        break;

    case 'ubs':
        $itens = obterUbsPorMunicipio($uf, $municipio, $codigoMunicipio);
        if (isset($itens['erro'])) {
            responderJson([
                'erro' => 'Falha ao buscar UBS.',
            ], 502);
        }

        responderJson([
            'erro' => '',
            'uf' => $uf,
            'cidade' => $municipio,
            'codigo_municipio' => $codigoMunicipio,
            'total' => count($itens),
            'itens' => $itens,
        ]);
        break;

    case 'arboviroses':
        $doencaNormalizada = normalizarDoencaSolicitada($doenca);
        if ($doencaNormalizada === '') {
            responderJson(['erro' => 'Doença inválida.'], 400);
        }

        $itens = obterArbovirosesPorMunicipio($uf, $municipio, $doencaNormalizada, $codigoMunicipio);
        if (isset($itens['erro'])) {
            responderJson([
                'erro' => 'Falha ao buscar dados de arboviroses.',
            ], 502);
        }

        responderJson([
            'erro' => '',
            'uf' => $uf,
            'cidade' => $municipio,
            'codigo_municipio' => $codigoMunicipio,
            'doenca' => $doencaNormalizada,
            'total' => count($itens),
            'itens' => $itens,
        ]);
        break;

    case 'mais-medicos':
        $itens = obterMaisMedicosPorMunicipio($uf, $municipio, $codigoMunicipio);
        if (isset($itens['erro'])) {
            responderJson([
                'erro' => 'Falha ao buscar dados do Mais Médicos.',
            ], 502);
        }

        responderJson([
            'erro' => '',
            'uf' => $uf,
            'cidade' => $municipio,
            'codigo_municipio' => $codigoMunicipio,
            'total' => count($itens),
            'itens' => $itens,
        ]);
}
