<?php

function chamarApi(string $url): array
{
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 PHP Saúde Demo'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    if ($error) {
        return ['erro' => $error];
    }

    if ($httpCode !== 200) {
        return ['erro' => 'Erro HTTP: ' . $httpCode];
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        return ['erro' => 'Resposta inválida da API'];
    }

    return $data;
}

function normalizarTexto(string $texto): string
{
    $texto = trim($texto);
    $texto = preg_replace('/\s+/', ' ', $texto) ?? $texto;
    return $texto;
}

function extrairValor(array $item, array $campos): string
{
    foreach ($campos as $campo) {
        if (isset($item[$campo]) && $item[$campo] !== null && $item[$campo] !== '') {
            return normalizarTexto((string) $item[$campo]);
        }
    }

    return '';
}

function obterEstadosECidades(): array
{
    $urls = [
        'https://apidadosabertos.saude.gov.br/macrorregiao-e-regiao-de-saude/municipio',
        'https://apidadosabertos.saude.gov.br/v1/macrorregiao-e-regiao-de-saude/municipio'
    ];

    $dados = ['erro' => 'Não foi possível consultar a API'];

    foreach ($urls as $url) {
        $tentativa = chamarApi($url);
        if (!isset($tentativa['erro'])) {
            $dados = $tentativa;
            break;
        }
        $dados = $tentativa;
    }

    $estados = [];
    $cidadesPorEstado = [];
    $registros = [];

    if (isset($dados['erro'])) {
        return [
            'erro' => $dados['erro'],
            'estados' => [],
            'cidadesPorEstado' => [],
            'registros' => []
        ];
    }

    foreach ($dados as $item) {
        if (!is_array($item)) {
            continue;
        }

        $uf = extrairValor($item, ['uf', 'sigla_uf', 'estado']);
        $municipio = extrairValor($item, ['municipio', 'nome_municipio', 'cidade']);
        $regiao = extrairValor($item, ['regiao_saude', 'nome_regiao_saude', 'regiao']);
        $macrorregiao = extrairValor($item, ['macrorregiao', 'nome_macrorregiao']);

        if ($uf === '' || $municipio === '') {
            continue;
        }

        $uf = strtoupper($uf);

        $estados[$uf] = $uf;
        $cidadesPorEstado[$uf][] = $municipio;

        $chave = $uf . '|' . mb_strtolower($municipio);
        $registros[$chave] = [
            'uf' => $uf,
            'municipio' => $municipio,
            'regiao_saude' => $regiao,
            'macrorregiao' => $macrorregiao
        ];
    }

    ksort($estados);

    foreach ($cidadesPorEstado as $uf => $cidades) {
        $cidades = array_unique($cidades);
        natcasesort($cidades);
        $cidadesPorEstado[$uf] = array_values($cidades);
    }

    $registros = array_values($registros);

    usort($registros, function (array $a, array $b): int {
        return [$a['uf'], $a['municipio']] <=> [$b['uf'], $b['municipio']];
    });

    return [
        'estados' => array_values($estados),
        'cidadesPorEstado' => $cidadesPorEstado,
        'registros' => $registros
    ];
}
