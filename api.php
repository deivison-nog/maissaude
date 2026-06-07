<?php

/** URL base da API pública usada pelas integrações do projeto. */
const API_SAUDE_BASE_URL = 'https://apidadosabertos.saude.gov.br';

/** Limite suficiente para cobrir o maior conjunto conhecido de municípios por UF. */
const MAX_MUNICIPIOS_POR_UF = 860;

/** Quantidade máxima de pares chave/valor incluídos em resumos genéricos. */
const RESUMO_ITEM_LIMITE_CAMPOS = 4;

function sanitizarUrlParaDebug(string $url): string
{
    $partes = parse_url($url);
    if (!is_array($partes)) {
        return $url;
    }

    $base = '';

    if (isset($partes['scheme'])) {
        $base .= $partes['scheme'] . '://';
    }

    if (isset($partes['host'])) {
        $base .= $partes['host'];
    }

    if (isset($partes['port'])) {
        $base .= ':' . $partes['port'];
    }

    return $base . ($partes['path'] ?? '');
}

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
        return ['erro' => $error, 'url' => sanitizarUrlParaDebug($url)];
    }

    if ($httpCode !== 200) {
        return ['erro' => 'Erro HTTP: ' . $httpCode, 'url' => sanitizarUrlParaDebug($url)];
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        return [
            'erro' => 'Resposta inválida da API',
            'url' => sanitizarUrlParaDebug($url),
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

function extrairPrimeiroValor(array $item, array $campos = [], array $caminhos = []): string
{
    $valor = $campos !== [] ? extrairValor($item, $campos) : '';
    if ($valor !== '') {
        return $valor;
    }

    foreach ($caminhos as $caminho) {
        $valor = extrairValorAninhado($item, $caminho);
        if ($valor !== '') {
            return $valor;
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

function construirUrlApiSaude(string $caminho, array $parametros = []): string
{
    $url = rtrim(API_SAUDE_BASE_URL, '/') . '/' . ltrim($caminho, '/');
    $parametrosFiltrados = [];

    foreach ($parametros as $chave => $valor) {
        if ($valor === null) {
            continue;
        }

        if (is_string($valor)) {
            $valor = trim($valor);
        }

        if ($valor === '') {
            continue;
        }

        $parametrosFiltrados[$chave] = $valor;
    }

    if ($parametrosFiltrados === []) {
        return $url;
    }

    return $url . '?' . http_build_query($parametrosFiltrados);
}

function obterListaApiSaude(string $caminho, array $parametros = []): array
{
    $url = construirUrlApiSaude($caminho, $parametros);
    $dados = chamarApi($url);

    if (isset($dados['erro'])) {
        return $dados;
    }

    $lista = encontrarListaDeObjetos($dados);
    if ($lista !== []) {
        return $lista;
    }

    if ($dados !== [] && !array_is_list($dados)) {
        return [$dados];
    }

    return [
        'erro' => 'Estrutura JSON não reconhecida',
        'url' => sanitizarUrlParaDebug($url),
        'json_bruto' => $dados
    ];
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

    $url = construirUrlApiSaude('/macrorregiao-e-regiao-de-saude/municipio', [
        'sigla_uf' => $uf,
        'limit' => MAX_MUNICIPIOS_POR_UF,
        'offset' => 0,
    ]);
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
            'url' => sanitizarUrlParaDebug($url)
        ];
    }

    $fallback = obterListaMunicipiosIbge($uf);

    if (isset($fallback['erro'])) {
        return [
            'erro' => 'Falha ao buscar municípios nas APIs disponíveis',
            'api_saude' => $erroApiSaude ?? ['erro' => 'Estrutura JSON não reconhecida', 'url' => sanitizarUrlParaDebug($url)],
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

function obterRegistroMunicipio(array $registros, string $uf, string $cidade): ?array
{
    $cidadeNormalizada = mb_strtolower(limparNomeMunicipio($cidade));

    foreach ($registros as $registro) {
        if (!isset($registro['uf'], $registro['municipio'])) {
            continue;
        }

        if ($registro['uf'] !== $uf) {
            continue;
        }

        if (mb_strtolower(limparNomeMunicipio((string) $registro['municipio'])) !== $cidadeNormalizada) {
            continue;
        }

        return $registro;
    }

    return null;
}

function montarEnderecoServicoSaude(array $item): string
{
    $logradouro = extrairPrimeiroValor($item, [
        'endereco', 'logradouro', 'nome_logradouro', 'logradouro_estabelecimento', 'endereco_estabelecimento'
    ], [
        ['endereco', 'logradouro'],
        ['endereco', 'nome_logradouro'],
        ['localizacao', 'endereco']
    ]);
    $numero = extrairPrimeiroValor($item, ['numero', 'numero_endereco', 'numero_estabelecimento'], [['endereco', 'numero']]);
    $complemento = extrairPrimeiroValor($item, ['complemento', 'complemento_endereco'], [['endereco', 'complemento']]);
    $bairro = extrairPrimeiroValor($item, ['bairro'], [['endereco', 'bairro']]);
    $municipio = extrairPrimeiroValor($item, ['municipio', 'nome_municipio', 'cidade'], [['endereco', 'municipio']]);
    $uf = extrairPrimeiroValor($item, ['uf', 'sigla_uf'], [['endereco', 'uf']]);
    $cep = extrairPrimeiroValor($item, ['cep'], [['endereco', 'cep']]);

    $partes = [];
    $linhaLogradouro = implode(', ', array_filter([$logradouro, $numero, $complemento], static fn(string $valor): bool => $valor !== ''));
    $linhaLocalidade = implode(' - ', array_filter([
        $bairro,
        implode('/', array_filter([$municipio, $uf], static fn(string $valor): bool => $valor !== ''))
    ], static fn(string $valor): bool => $valor !== ''));

    if ($linhaLogradouro !== '') {
        $partes[] = $linhaLogradouro;
    }

    if ($linhaLocalidade !== '') {
        $partes[] = $linhaLocalidade;
    }

    if ($cep !== '') {
        $partes[] = 'CEP ' . $cep;
    }

    return implode(' | ', $partes);
}

function resumirItemGenerico(array $item): string
{
    $pares = [];

    foreach ($item as $chave => $valor) {
        if (is_array($valor) || $valor === null || $valor === '') {
            continue;
        }

        $pares[] = sprintf('%s: %s', (string) $chave, normalizarTexto((string) $valor));
        if (count($pares) >= RESUMO_ITEM_LIMITE_CAMPOS) {
            break;
        }
    }

    return implode(' | ', $pares);
}

function normalizarListaServicosSaude(array $lista, string $tipoPadrao = ''): array
{
    $itens = [];

    foreach ($lista as $item) {
        if (!is_array($item)) {
            continue;
        }

        $nome = extrairPrimeiroValor($item, [
            'nome_fantasia', 'nomeFantasia', 'nome_estabelecimento', 'estabelecimento',
            'razao_social', 'razaoSocial', 'nome'
        ]);
        $tipo = extrairPrimeiroValor($item, [
            'tipo_unidade', 'tipoUnidade', 'descricao_subtipo_unidade', 'subtipo_unidade',
            'categoria', 'natureza_organizacao', 'tipo'
        ]);
        $telefone = extrairPrimeiroValor($item, [
            'telefone', 'telefone1', 'telefone_1', 'numero_telefone', 'contato', 'telefone_estabelecimento'
        ], [
            ['contato', 'telefone'],
            ['endereco', 'telefone']
        ]);
        $codigo = extrairPrimeiroValor($item, ['cnes', 'codigo_cnes', 'codigo', 'id']);
        $leitos = extrairPrimeiroValor($item, ['leitos', 'qtd_leitos', 'qt_leitos', 'quantidade_leitos', 'total_leitos']);
        $endereco = montarEnderecoServicoSaude($item);

        $registro = array_filter([
            'nome' => $nome,
            'tipo' => $tipo !== '' ? $tipo : $tipoPadrao,
            'endereco' => $endereco,
            'telefone' => $telefone,
            'codigo' => $codigo,
            'leitos' => $leitos,
            'descricao' => resumirItemGenerico($item),
        ], static fn(string $valor): bool => $valor !== '');

        if (!isset($registro['nome']) && isset($registro['descricao'])) {
            $registro['nome'] = $registro['descricao'];
        }

        if ($registro !== []) {
            $itens[] = $registro;
        }
    }

    return $itens;
}

function normalizarListaArboviroses(array $lista, string $doenca): array
{
    $itens = [];

    foreach ($lista as $item) {
        if (!is_array($item)) {
            continue;
        }

        $municipio = extrairPrimeiroValor($item, ['municipio', 'cidade', 'nome_municipio']);
        $uf = extrairPrimeiroValor($item, ['uf', 'sigla_uf']);
        $periodo = extrairPrimeiroValor($item, ['ano', 'periodo', 'competencia', 'semana_epidemiologica', 'data_notificacao']);
        $casos = extrairPrimeiroValor($item, ['casos', 'quantidade', 'notificacoes', 'numero_casos', 'total']);
        $classificacao = extrairPrimeiroValor($item, ['classificacao', 'situacao', 'status']);

        $titulo = implode(' - ', array_filter([
            ucfirst($doenca),
            implode('/', array_filter([$municipio, $uf], static fn(string $valor): bool => $valor !== ''))
        ], static fn(string $valor): bool => $valor !== ''));

        $registro = array_filter([
            'titulo' => $titulo !== '' ? $titulo : ucfirst($doenca),
            'periodo' => $periodo,
            'casos' => $casos,
            'observacao' => $classificacao !== '' ? $classificacao : resumirItemGenerico($item),
        ], static fn(string $valor): bool => $valor !== '');

        if ($registro !== []) {
            $itens[] = $registro;
        }
    }

    return $itens;
}

function normalizarListaMaisMedicos(array $lista): array
{
    $itens = [];

    foreach ($lista as $item) {
        if (!is_array($item)) {
            continue;
        }

        $registro = array_filter([
            'nome' => extrairPrimeiroValor($item, ['nome_profissional', 'profissional', 'nome']),
            'tipo' => extrairPrimeiroValor($item, ['ocupacao', 'cargo', 'funcao']),
            'endereco' => extrairPrimeiroValor($item, ['unidade', 'estabelecimento', 'nome_unidade']),
            'descricao' => resumirItemGenerico($item),
        ], static fn(string $valor): bool => $valor !== '');

        if (!isset($registro['nome']) && isset($registro['descricao'])) {
            $registro['nome'] = $registro['descricao'];
        }

        if ($registro !== []) {
            $itens[] = $registro;
        }
    }

    return $itens;
}

function obterEstabelecimentosPorMunicipio(string $codigoMunicipio, string $uf = '', string $cidade = ''): array
{
    $lista = obterListaApiSaude('/cnes/estabelecimentos', [
        'codigo_municipio' => $codigoMunicipio,
        'uf' => $uf,
        'municipio' => $cidade,
    ]);

    return isset($lista['erro']) ? $lista : normalizarListaServicosSaude($lista, 'Estabelecimento de saúde');
}

function obterHospitaisPorMunicipio(string $uf, string $cidade, string $codigoMunicipio = ''): array
{
    $lista = obterListaApiSaude('/assistencia-a-saude/hospitais-e-leitos', [
        'uf' => $uf,
        'municipio' => $cidade,
        'codigo_municipio' => $codigoMunicipio,
    ]);

    return isset($lista['erro']) ? $lista : normalizarListaServicosSaude($lista, 'Hospital');
}

function obterUbsPorMunicipio(string $uf, string $cidade, string $codigoMunicipio = ''): array
{
    $lista = obterListaApiSaude('/assistencia-a-saude/unidade-basicas-de-saude', [
        'uf' => $uf,
        'municipio' => $cidade,
        'codigo_municipio' => $codigoMunicipio,
    ]);

    return isset($lista['erro']) ? $lista : normalizarListaServicosSaude($lista, 'UBS');
}

function obterArbovirosesPorMunicipio(string $uf, string $cidade, string $doenca, string $codigoMunicipio = ''): array
{
    $mapa = [
        'dengue' => '/arboviroses/dengue',
        'zikavirus' => '/arboviroses/zikavirus',
        'zika' => '/arboviroses/zikavirus',
        'chikungunya' => '/arboviroses/chikungunya',
    ];
    $doencaNormalizada = strtolower(trim($doenca));
    $caminho = $mapa[$doencaNormalizada] ?? null;

    if ($caminho === null) {
        return ['erro' => 'Doença inválida.'];
    }

    $lista = obterListaApiSaude($caminho, [
        'uf' => $uf,
        'municipio' => $cidade,
        'codigo_municipio' => $codigoMunicipio,
    ]);

    return isset($lista['erro']) ? $lista : normalizarListaArboviroses($lista, $doencaNormalizada === 'zika' ? 'zikavirus' : $doencaNormalizada);
}

function obterMaisMedicosPorMunicipio(string $uf, string $cidade, string $codigoMunicipio = ''): array
{
    $lista = obterListaApiSaude('/atencao-primaria/pmmb-profissionais-ativos', [
        'uf' => $uf,
        'municipio' => $cidade,
        'codigo_municipio' => $codigoMunicipio,
    ]);

    return isset($lista['erro']) ? $lista : normalizarListaMaisMedicos($lista);
}
