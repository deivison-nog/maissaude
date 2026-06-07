<?php

const MAX_MUNICIPIOS_POR_UF = 860;

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

function removerAcentos(string $texto): string
{
    $convertido = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
    return $convertido !== false ? $convertido : $texto;
}

function nomeEstadoParaSigla(string $nome): string
{
    $nome = mb_strtoupper(normalizarTexto(removerAcentos($nome)));

    $mapa = [
        'ACRE' => 'AC',
        'ALAGOAS' => 'AL',
        'AMAPA' => 'AP',
        'AMAZONAS' => 'AM',
        'BAHIA' => 'BA',
        'CEARA' => 'CE',
        'DISTRITO FEDERAL' => 'DF',
        'ESPIRITO SANTO' => 'ES',
        'GOIAS' => 'GO',
        'MARANHAO' => 'MA',
        'MATO GROSSO' => 'MT',
        'MATO GROSSO DO SUL' => 'MS',
        'MINAS GERAIS' => 'MG',
        'PARA' => 'PA',
        'PARAIBA' => 'PB',
        'PARANA' => 'PR',
        'PERNAMBUCO' => 'PE',
        'PIAUI' => 'PI',
        'RIO DE JANEIRO' => 'RJ',
        'RIO GRANDE DO NORTE' => 'RN',
        'RIO GRANDE DO SUL' => 'RS',
        'RONDONIA' => 'RO',
        'RORAIMA' => 'RR',
        'SANTA CATARINA' => 'SC',
        'SAO PAULO' => 'SP',
        'SERGIPE' => 'SE',
        'TOCANTINS' => 'TO'
    ];

    return $mapa[$nome] ?? $nome;
}

function limparNomeMunicipio(string $municipio): string
{
    $municipio = normalizarTexto($municipio);
    $municipio = preg_replace('/^[A-Z]{2}\s*-\s*/u', '', $municipio) ?? $municipio;
    return trim($municipio);
}

function normalizarChaveCampo(string $chave): string
{
    $chave = mb_strtolower(removerAcentos($chave));
    return preg_replace('/[^a-z0-9]/', '', $chave) ?? $chave;
}

function converterValorParaTexto($valor): string
{
    if (is_string($valor) || is_numeric($valor)) {
        return normalizarTexto((string) $valor);
    }

    if (is_array($valor)) {
        foreach (['nome', 'name', 'sigla', 'codigo', 'id'] as $campo) {
            if (array_key_exists($campo, $valor)) {
                return converterValorParaTexto($valor[$campo]);
            }
        }
    }

    return '';
}

function extrairValor(array $item, array $campos): string
{
    foreach ($campos as $campo) {
        if (array_key_exists($campo, $item)) {
            $valor = converterValorParaTexto($item[$campo]);
            if ($valor !== '') {
                return $valor;
            }
        }
    }

    $mapaNormalizado = [];
    foreach ($item as $chave => $valor) {
        $mapaNormalizado[normalizarChaveCampo((string) $chave)] = $valor;
    }

    foreach ($campos as $campo) {
        $campoNormalizado = normalizarChaveCampo($campo);
        if (!array_key_exists($campoNormalizado, $mapaNormalizado)) {
            continue;
        }

        $valor = converterValorParaTexto($mapaNormalizado[$campoNormalizado]);
        if ($valor !== '') {
            return $valor;
        }
    }

    return '';
}

function extrairValorAninhado(array $item, array $caminho): string
{
    $valor = $item;

    foreach ($caminho as $chave) {
        if (!is_array($valor) || !array_key_exists($chave, $valor)) {
            return '';
        }

        $valor = $valor[$chave];
    }

    if ($valor === null || $valor === '') {
        return '';
    }

    return normalizarTexto((string) $valor);
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

function obterListaMunicipiosIbge(string $uf): array
{
    $url = 'https://servicodados.ibge.gov.br/api/v1/localidades/estados/' . urlencode($uf) . '/municipios';
    $dados = chamarApi($url);

    if (isset($dados['erro'])) {
        return $dados;
    }

    if (!ehListaDeObjetos($dados)) {
        return [
            'erro' => 'Estrutura JSON não reconhecida',
            'url' => $url,
            'json_bruto' => $dados
        ];
    }

    return array_values($dados);
}

function listaContemMunicipiosUtilizaveis(array $lista): bool
{
    foreach ($lista as $item) {
        if (!is_array($item)) {
            continue;
        }

        $municipio = extrairValor($item, ['municipio', 'nome_municipio', 'nomeMunicipio', 'municipio_nome', 'cidade', 'nome']);
        if ($municipio !== '') {
            return true;
        }
    }

    return false;
}

function obterListaMunicipiosPorUf(string $uf): array
{
    $uf = strtoupper(trim($uf));

    if ($uf === '') {
        return ['erro' => 'UF não informada'];
    }

    $url = 'https://apidadosabertos.saude.gov.br/macrorregiao-e-regiao-de-saude/municipio?sigla_uf=' . urlencode($uf) . '&limit=' . MAX_MUNICIPIOS_POR_UF . '&offset=0';
    $dados = chamarApi($url);
    $erroApiSaude = null;

    if (isset($dados['erro'])) {
        $erroApiSaude = $dados;
    } else {
        $lista = encontrarListaDeObjetos($dados);

        if ($lista !== [] && listaContemMunicipiosUtilizaveis($lista)) {
            return $lista;
        }

        $erroApiSaude = [
            'erro' => 'Estrutura da lista sem municípios utilizáveis',
            'url' => $url
        ];
    }

    $fallback = obterListaMunicipiosIbge($uf);

    if (isset($fallback['erro'])) {
        return [
            'erro' => 'Falha ao buscar municípios nas APIs disponíveis',
            'api_saude' => $erroApiSaude ?? ['erro' => 'Estrutura JSON não reconhecida', 'url' => $url],
            'api_ibge' => $fallback
        ];
    }

    return $fallback;
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
            'registros' => []
        ];
    }

    $lista = obterListaMunicipiosPorUf($uf);

    if (isset($lista['erro'])) {
        return [
            'erro' => $lista['erro'],
            'estados' => $estados,
            'cidadesPorEstado' => [],
            'registros' => [],
            'debug' => $lista
        ];
    }

    $cidadesPorEstado = [];
    $registrosIndexados = [];

    foreach ($lista as $item) {
        if (!is_array($item)) {
            continue;
        }

        $ufRegistro = extrairValor($item, ['sigla_uf', 'siglaUf', 'uf', 'estado', 'uf_sigla']);
        $municipio = extrairValor($item, ['municipio', 'nome_municipio', 'nomeMunicipio', 'municipio_nome', 'cidade', 'nome']);
        $regiaoSaude = extrairValor($item, ['regiao_saude', 'nome_regiao_saude']);
        $macrorregiaoSaude = extrairValor($item, ['macrorregiao_saude', 'macrorregiao', 'nome_macrorregiao']);
        $codigoMunicipio = extrairValor($item, ['codigo_municipio', 'codigoMunicipio', 'codigo_ibge', 'codigoIbge', 'id']);

        if ($ufRegistro === '') {
            $ufRegistro = extrairValorAninhado($item, ['microrregiao', 'mesorregiao', 'UF', 'sigla']);
        }

        if ($regiaoSaude === '') {
            $regiaoSaude = extrairValorAninhado($item, ['microrregiao', 'nome']);
        }

        if ($macrorregiaoSaude === '') {
            $macrorregiaoSaude = extrairValorAninhado($item, ['microrregiao', 'mesorregiao', 'nome']);
        }

        if ($ufRegistro === '') {
            $ufRegistro = $uf;
        }

        if ($ufRegistro === '' || $municipio === '') {
            continue;
        }

        $ufRegistro = nomeEstadoParaSigla($ufRegistro);
        $municipio = limparNomeMunicipio($municipio);

        if ($ufRegistro === '' || $municipio === '') {
            continue;
        }

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
        'registros' => $registros
    ];
}
