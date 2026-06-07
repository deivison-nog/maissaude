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
            'User-Agent: Mozilla/5.0 PHP Saude Demo'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    if ($error) {
        return ['erro' => $error, 'url' => $url];
    }

    if ($httpCode !== 200) {
        return ['erro' => 'Erro HTTP: ' . $httpCode, 'url' => $url];
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        return [
            'erro' => 'Resposta inválida da API',
            'url' => $url,
            'resposta_bruta' => mb_substr((string) $response, 0, 1000)
        ];
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
        if (array_key_exists($campo, $item) && $item[$campo] !== null && $item[$campo] !== '') {
            return normalizarTexto((string) $item[$campo]);
        }
    }

    return '';
}

function ehListaDeObjetos(array $valor): bool
{
    if ($valor === []) {
        return false;
    }

    $primeiro = reset($valor);
    return is_array($primeiro);
}

function encontrarListaDeObjetos(array $dados): array
{
    if (ehListaDeObjetos($dados)) {
        return array_values($dados);
    }

    $chavesPreferidas = ['data', 'items', 'result', 'results', 'records', 'rows'];

    foreach ($chavesPreferidas as $chave) {
        if (isset($dados[$chave]) && is_array($dados[$chave]) && ehListaDeObjetos($dados[$chave])) {
            return array_values($dados[$chave]);
        }
    }

    foreach ($dados as $valor) {
        if (is_array($valor) && ehListaDeObjetos($valor)) {
            return array_values($valor);
        }
    }

    foreach ($dados as $valor) {
        if (is_array($valor)) {
            $lista = encontrarListaDeObjetos($valor);
            if ($lista !== []) {
                return $lista;
            }
        }
    }

    return [];
}

function obterListaMunicipiosPorUf(string $uf): array
{
    $uf = strtoupper(trim($uf));

    if ($uf === '') {
        return ['erro' => 'UF não informada'];
    }

    $offset = 0;
    $limit = 860;
    $todos = [];
    $ultimaResposta = null;

    while (true) {
        $url = 'https://apidadosabertos.saude.gov.br/macrorregiao-e-regiao-de-saude/municipio?sigla_uf=' . urlencode($uf) . '&limit=' . $limit . '&offset=' . $offset;
        $dados = chamarApi($url);
        $ultimaResposta = $dados;

        if (isset($dados['erro'])) {
            return $dados;
        }

        $lista = encontrarListaDeObjetos($dados);

        if ($lista === []) {
            return [
                'erro' => 'Estrutura JSON não reconhecida',
                'url' => $url,
                'json_bruto' => $dados
            ];
        }

        $quantidade = count($lista);
        $todos = array_merge($todos, $lista);

        if ($quantidade < $limit) {
            break;
        }

        $offset += $limit;

        if ($offset > 10000) {
            break;
        }
    }

    return $todos;
}

function obterEstadosFixos(): array
{
    return [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA',
        'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN',
        'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'
    ];
}

function obterEstadosECidades(string $uf = ''): array
{
    $estados = obterEstadosFixos();

    if ($uf === '') {
        return [
            'erro' => '',
            'estados' => $estados,
            'cidadesPorEstado' => [],
            'registros' => [],
            'total_registros' => 0,
            'total_estados' => count($estados)
        ];
    }

    $lista = obterListaMunicipiosPorUf($uf);

    if (isset($lista['erro'])) {
        return [
            'erro' => $lista['erro'],
            'estados' => $estados,
            'cidadesPorEstado' => [],
            'registros' => [],
            'debug' => $lista,
            'total_registros' => 0,
            'total_estados' => count($estados)
        ];
    }

    $cidadesPorEstado = [];
    $registrosIndexados = [];

    foreach ($lista as $item) {
        if (!is_array($item)) {
            continue;
        }

        $ufRegistro = extrairValor($item, ['sigla_uf', 'uf', 'estado']);
        $municipio = extrairValor($item, ['municipio', 'nome_municipio', 'cidade']);
        $regiaoSaude = extrairValor($item, ['regiao_saude', 'nome_regiao_saude']);
        $macrorregiaoSaude = extrairValor($item, ['macrorregiao_saude', 'macrorregiao', 'nome_macrorregiao']);
        $codigoMunicipio = extrairValor($item, ['codigo_municipio', 'codigo_ibge']);

        if ($ufRegistro === '' || $municipio === '') {
            continue;
        }

        $ufRegistro = strtoupper($ufRegistro);
        $municipio = normalizarTexto($municipio);

        $cidadesPorEstado[$ufRegistro][] = $municipio;

        $chave = $ufRegistro . '|' . mb_strtolower($municipio);
        $registrosIndexados[$chave] = [
            'uf' => $ufRegistro,
            'municipio' => $municipio,
            'codigo_municipio' => $codigoMunicipio,
            'regiao_saude' => $regiaoSaude,
            'macrorregiao_saude' => $macrorregiaoSaude
        ];
    }

    foreach ($cidadesPorEstado as $ufKey => $cidades) {
        $cidades = array_unique($cidades);
        natcasesort($cidades);
        $cidadesPorEstado[$ufKey] = array_values($cidades);
    }

    $registros = array_values($registrosIndexados);

    usort($registros, function (array $a, array $b): int {
        return [$a['uf'], $a['municipio']] <=> [$b['uf'], $b['municipio']];
    });

    return [
        'erro' => '',
        'estados' => $estados,
        'cidadesPorEstado' => $cidadesPorEstado,
        'registros' => $registros,
        'total_registros' => count($lista),
        'total_estados' => count($estados)
    ];
}
