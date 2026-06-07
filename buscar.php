<?php

require_once __DIR__ . '/api.php';

header('Content-Type: application/json; charset=UTF-8');

function normalizarMunicipioParaComparacao(string $municipio): string
{
    return mb_strtolower(limparNomeMunicipio($municipio));
}

$action = trim((string) ($_GET['action'] ?? 'estados'));
$uf = strtoupper(trim((string) ($_GET['uf'] ?? '')));
$cidade = trim((string) ($_GET['cidade'] ?? ''));

if ($action === 'estados') {
    echo json_encode([
        'erro' => '',
        'estados' => obterEstadosFixos(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($uf === '') {
    http_response_code(400);
    echo json_encode([
        'erro' => 'UF não informada.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$dados = obterEstadosECidades($uf);

if ($dados['erro'] !== '') {
    http_response_code(502);
    echo json_encode([
        'erro' => $dados['erro'],
        'debug' => $dados['debug'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'cidades') {
    echo json_encode([
        'erro' => '',
        'uf' => $uf,
        'cidades' => $dados['cidadesPorEstado'][$uf] ?? [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'resultado') {
    if ($cidade === '') {
        http_response_code(400);
        echo json_encode([
            'erro' => 'Cidade não informada.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $cidadeNormalizada = normalizarMunicipioParaComparacao($cidade);
    $registroEncontrado = null;

    foreach ($dados['registros'] as $registro) {
        if ($registro['uf'] !== $uf) {
            continue;
        }

        if (normalizarMunicipioParaComparacao($registro['municipio']) === $cidadeNormalizada) {
            $registroEncontrado = $registro;
            break;
        }
    }

    if ($registroEncontrado === null) {
        http_response_code(404);
        echo json_encode([
            'erro' => 'Cidade não encontrada para a UF informada.',
            'uf' => $uf,
            'cidade' => $cidade,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'erro' => '',
        'resultado' => $registroEncontrado,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode([
    'erro' => 'Ação inválida.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
