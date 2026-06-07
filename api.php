<?php

/** URL base da API pública usada pelas integrações do projeto. */
const API_SAUDE_BASE_URL = 'https://apidadosabertos.saude.gov.br';

/** Limite suficiente para cobrir o maior conjunto conhecido de municípios por UF. */
const MAX_MUNICIPIOS_POR_UF = 860;

/** Quantidade máxima de pares chave/valor incluídos em resumos genéricos. */
const RESUMO_ITEM_LIMITE_CAMPOS = 8;

/** Itens por página ao paginar o endpoint CNES de estabelecimentos. */
const CNES_ESTABELECIMENTOS_POR_PAGINA = 50;

/** Total máximo de estabelecimentos buscados por município (evita excesso de chamadas). */
const CNES_ESTABELECIMENTOS_MAX_TOTAL = 2000;

if (!function_exists('array_is_list')) {
    function array_is_list(array $array): bool
    {
        if ($array === []) {
            return true;
        }

        $indiceEsperado = 0;

        foreach ($array as $chave => $_valor) {
            if ($chave !== $indiceEsperado) {
                return false;
            }

            $indiceEsperado++;
        }

        return true;
    }
}

function textoParaMaiusculas(string $texto): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($texto) : strtoupper($texto);
}

function textoParaMinusculas(string $texto): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($texto) : strtolower($texto);
}

function recortarTexto(string $texto, int $inicio, int $limite): string
{
    return function_exists('mb_substr') ? mb_substr($texto, $inicio, $limite) : substr($texto, $inicio, $limite);
}

function extrairCodigoHttpCabecalhos(array $cabecalhos): int
{
    foreach (array_reverse($cabecalhos) as $cabecalho) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $cabecalho, $matches) === 1) {
            return (int) $matches[1];
        }
    }

    return 0;
}

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
    $response = false;
    $httpCode = 0;
    $error = '';

    if (function_exists('curl_init')) {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
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
    } else {
        $contexto = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'ignore_errors' => true,
                'header' => implode("\r\n", [
                    'Accept: application/json',
                    'User-Agent: Mozilla/5.0 PHP Saude Demo',
                ]),
            ],
        ]);

        $response = @file_get_contents($url, false, $contexto);
        $cabecalhos = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
        $httpCode = extrairCodigoHttpCabecalhos($cabecalhos);

        if ($response === false) {
            $error = 'Falha ao acessar endpoint remoto';
        }
    }

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
            'resposta_bruta' => recortarTexto((string) $response, 0, 1000)
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
    if (!function_exists('iconv')) {
        return $texto;
    }

    $convertido = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
    return $convertido !== false ? $convertido : $texto;
}

function nomeEstadoParaSigla(string $nome): string
{
    $nome = textoParaMaiusculas(normalizarTexto(removerAcentos($nome)));

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

function normalizarCodigoMunicipio(string $codigo): string
{
    $codigo = preg_replace('/\D/', '', $codigo) ?? $codigo;

    if (strlen($codigo) === 7) {
        $codigo = substr($codigo, 0, 6);
    }

    return $codigo;
}

function obterCodigoUf(string $uf): string
{
    $mapa = [
        'RO' => '11',
        'AC' => '12',
        'AM' => '13',
        'RR' => '14',
        'PA' => '15',
        'AP' => '16',
        'TO' => '17',
        'MA' => '21',
        'PI' => '22',
        'CE' => '23',
        'RN' => '24',
        'PB' => '25',
        'PE' => '26',
        'AL' => '27',
        'SE' => '28',
        'BA' => '29',
        'MG' => '31',
        'ES' => '32',
        'RJ' => '33',
        'SP' => '35',
        'PR' => '41',
        'SC' => '42',
        'RS' => '43',
        'MS' => '50',
        'MT' => '51',
        'GO' => '52',
        'DF' => '53',
    ];

    $uf = strtoupper(trim($uf));
    return $mapa[$uf] ?? '';
}

function descricaoTipoUnidade(int $codigo): string
{
    $mapa = [
        1  => 'Posto de Saúde',
        2  => 'Unidade de Saúde da Família',
        4  => 'Policlínica',
        5  => 'Hospital Geral',
        7  => 'Hospital Especializado',
        15 => 'Unidade Mista',
        20 => 'Pronto-socorro Geral',
        21 => 'Pronto-socorro Especializado',
        22 => 'Pronto-socorro de Trauma e Ortopedia',
        32 => 'Telesaúde',
        36 => 'Clínica/Centro de Especialidade',
        39 => 'SADT Isolado',
        40 => 'Unidade Móvel Terrestre',
        42 => 'Unidade Móvel de Urgência',
        43 => 'Farmácia',
        50 => 'Unidade de Vigilância em Saúde',
        60 => 'Cooperativa de Saúde',
        61 => 'Centro de Parto Normal',
        62 => 'Hospital/Dia',
        64 => 'Central de Regulação',
        67 => 'Laboratório de Saúde Pública',
        68 => 'Secretaria de Saúde',
        69 => 'Centro de Hemoterapia',
        70 => 'Centro de Atenção Psicossocial',
        71 => 'Centro de Apoio à Saúde da Família',
        72 => 'Unidade de Saúde Indígena',
        73 => 'Pronto Atendimento',
        74 => 'Polo da Academia da Saúde',
        75 => 'Telessaúde',
        76 => 'Central de Regulação de Urgências',
        77 => 'Serviço de Atenção Domiciliar',
        78 => 'Unidade de Saúde da Família Fluvial',
        79 => 'Unidade Odontológica Móvel',
        80 => 'Laboratório de Saúde Pública',
        81 => 'Unidade de Atenção Residencial',
        82 => 'Unidade de Saúde Prisional',
        83 => 'Polo de Promoção da Saúde',
        85 => 'Centro de Imunização',
    ];

    return $mapa[$codigo] ?? '';
}

function extrairMensagemErroApi(array $dados): string
{
    foreach (['erro', 'error', 'message', 'detail'] as $campo) {
        if (!array_key_exists($campo, $dados)) {
            continue;
        }

        $valor = $dados[$campo];
        if (is_string($valor) && trim($valor) !== '') {
            return normalizarTexto($valor);
        }
    }

    return '';
}

function normalizarChaveCampo(string $chave): string
{
    $chave = textoParaMinusculas(removerAcentos($chave));
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

    if (!array_is_list($valor)) {
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

    $chavesPreferidas = ['data', 'items', 'result', 'results', 'records', 'rows', 'estabelecimentos'];

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

    $mensagemErro = extrairMensagemErroApi($dados);
    if ($mensagemErro !== '') {
        return [
            'erro' => $mensagemErro,
            'url' => sanitizarUrlParaDebug($url),
            'json_bruto' => $dados,
        ];
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

function obterListaMunicipiosFallbackLocal(string $uf): array
{
    $uf = strtoupper(trim($uf));

    $municipiosPorUf = [
        'PA' => [
            'Abaetetuba', 'Abel Figueiredo', 'Acará', 'Afuá', 'Água Azul do Norte', 'Alenquer', 'Almeirim',
            'Altamira', 'Anajás', 'Ananindeua', 'Anapu', 'Augusto Corrêa', 'Aurora do Pará', 'Aveiro', 'Bagre',
            'Baião', 'Bannach', 'Barcarena', 'Belém', 'Belterra', 'Benevides', 'Bom Jesus do Tocantins',
            'Bonito', 'Bragança', 'Brasil Novo', 'Brejo Grande do Araguaia', 'Breu Branco', 'Breves', 'Bujaru',
            'Cachoeira do Arari', 'Cachoeira do Piriá', 'Cametá', 'Canaã dos Carajás', 'Capanema',
            'Capitão Poço', 'Castanhal', 'Chaves', 'Colares', 'Conceição do Araguaia', 'Concórdia do Pará',
            'Cumaru do Norte', 'Curionópolis', 'Curralinho', 'Curuá', 'Curuçá', 'Dom Eliseu',
            'Eldorado dos Carajás', 'Faro', 'Floresta do Araguaia', 'Garrafão do Norte', 'Goianésia do Pará',
            'Gurupá', 'Igarapé-Açu', 'Igarapé-Miri', 'Inhangapi', 'Ipixuna do Pará', 'Irituia', 'Itaituba',
            'Itupiranga', 'Jacareacanga', 'Jacundá', 'Juruti', 'Limoeiro do Ajuru', 'Mãe do Rio',
            'Magalhães Barata', 'Marabá', 'Maracanã', 'Marapanim', 'Marituba', 'Medicilândia', 'Melgaço',
            'Mocajuba', 'Moju', 'Mojuí dos Campos', 'Monte Alegre', 'Muaná', 'Nova Esperança do Piriá',
            'Nova Ipixuna', 'Nova Timboteua', 'Novo Progresso', 'Novo Repartimento', 'Óbidos', 'Oeiras do Pará',
            'Oriximiná', 'Ourém', 'Ourilândia do Norte', 'Pacajá', 'Palestina do Pará', 'Paragominas',
            'Parauapebas', "Pau D’Arco", 'Peixe-Boi', 'Piçarra', 'Placas', 'Ponta de Pedras', 'Portel',
            'Porto de Moz', 'Prainha', 'Primavera', 'Quatipuru', 'Redenção', 'Rio Maria', 'Rondon do Pará',
            'Rurópolis', 'Salinópolis', 'Salvaterra', 'Santa Bárbara do Pará', 'Santa Cruz do Arari',
            'Santa Izabel do Pará', 'Santa Luzia do Pará', 'Santa Maria das Barreiras', 'Santa Maria do Pará',
            'Santana do Araguaia', 'Santarém', 'Santarém Novo', 'Santo Antônio do Tauá',
            'São Caetano de Odivelas', 'São Domingos do Araguaia', 'São Domingos do Capim',
            'São Félix do Xingu', 'São Francisco do Pará', 'São Geraldo do Araguaia', 'São João da Ponta',
            'São João de Pirabas', 'São João do Araguaia', 'São Miguel do Guamá',
            'São Sebastião da Boa Vista', 'Sapucaia', 'Senador José Porfírio', 'Soure', 'Tailândia',
            'Terra Alta', 'Terra Santa', 'Tomé-Açu', 'Tracuateua', 'Trairão', 'Tucumã', 'Tucuruí',
            'Ulianópolis', 'Uruará', 'Vigia', 'Viseu', 'Vitória do Xingu', 'Xinguara',
        ],
    ];

    if (!isset($municipiosPorUf[$uf])) {
        return ['erro' => 'Fallback local não disponível para a UF informada'];
    }

    return array_map(
        static fn(string $municipio): array => [
            'sigla_uf' => $uf,
            'municipio' => $municipio,
        ],
        $municipiosPorUf[$uf]
    );
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

    if (!isset($fallback['erro'])) {
        return $fallback;
    }

    $fallbackLocal = obterListaMunicipiosFallbackLocal($uf);

    if (!isset($fallbackLocal['erro'])) {
        return $fallbackLocal;
    }

    return [
        'erro' => 'Falha ao buscar municípios nas APIs disponíveis',
        'api_saude' => $erroApiSaude ?? ['erro' => 'Estrutura JSON não reconhecida', 'url' => sanitizarUrlParaDebug($url)],
        'api_ibge' => $fallback,
        'api_local' => $fallbackLocal,
    ];
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

        $chave = $ufRegistro . '|' . textoParaMinusculas($municipio);
        $registrosIndexados[$chave] = [
            'uf' => $ufRegistro,
            'municipio' => $municipio,
            'codigo_municipio' => normalizarCodigoMunicipio($codigoMunicipio),
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
    $cidadeNormalizada = textoParaMinusculas(limparNomeMunicipio($cidade));

    foreach ($registros as $registro) {
        if (!isset($registro['uf'], $registro['municipio'])) {
            continue;
        }

        if ($registro['uf'] !== $uf) {
            continue;
        }

        if (textoParaMinusculas(limparNomeMunicipio((string) $registro['municipio'])) !== $cidadeNormalizada) {
            continue;
        }

        return $registro;
    }

    return null;
}

function montarEnderecoServicoSaude(array $item, array $contexto = []): string
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
    $bairro = extrairPrimeiroValor($item, ['bairro', 'bairro_estabelecimento'], [['endereco', 'bairro']]);
    $municipio = extrairPrimeiroValor($item, ['municipio', 'nome_municipio', 'cidade'], [['endereco', 'municipio']]);
    $uf = extrairPrimeiroValor($item, ['uf', 'sigla_uf'], [['endereco', 'uf']]);
    $cep = extrairPrimeiroValor($item, ['cep', 'codigo_cep_estabelecimento'], [['endereco', 'cep']]);

    if ($municipio === '' && isset($contexto['cidade']) && is_string($contexto['cidade'])) {
        $municipio = $contexto['cidade'];
    }

    if ($uf === '' && isset($contexto['uf']) && is_string($contexto['uf'])) {
        $uf = $contexto['uf'];
    }

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

function normalizarListaServicosSaude(array $lista, string $tipoPadrao = '', array $contexto = []): array
{
    $itens = [];

    foreach ($lista as $item) {
        if (!is_array($item)) {
            continue;
        }

        $nome = extrairPrimeiroValor($item, [
            'nome_fantasia', 'nomeFantasia', 'nome_estabelecimento', 'estabelecimento',
            'razao_social', 'razaoSocial', 'nome_razao_social', 'nome'
        ]);
        $razaoSocial = extrairPrimeiroValor($item, ['nome_razao_social', 'razao_social', 'razaoSocial']);
        $tipo = extrairPrimeiroValor($item, [
            'tipo_unidade', 'tipoUnidade', 'descricao_subtipo_unidade', 'subtipo_unidade',
            'categoria', 'natureza_organizacao', 'tipo'
        ]);

        if ($tipo === '' && isset($item['codigo_tipo_unidade'])) {
            $tipo = descricaoTipoUnidade((int) $item['codigo_tipo_unidade']);
        }
        $telefone = extrairPrimeiroValor($item, [
            'telefone', 'telefone1', 'telefone_1', 'numero_telefone', 'contato',
            'telefone_estabelecimento', 'numero_telefone_estabelecimento'
        ], [
            ['contato', 'telefone'],
            ['endereco', 'telefone']
        ]);
        $codigo = extrairPrimeiroValor($item, ['cnes', 'codigo_cnes', 'codigo', 'id']);
        $codigoEstabelecimento = extrairPrimeiroValor($item, ['codigo_estabelecimento_saude']);
        $cnpj = extrairPrimeiroValor($item, ['numero_cnpj_entidade', 'numero_cnpj', 'cnpj']);
        $email = extrairPrimeiroValor($item, ['endereco_email_estabelecimento', 'email']);
        $gestao = extrairPrimeiroValor($item, ['tipo_gestao', 'gestao']);
        $esferaAdministrativa = extrairPrimeiroValor($item, ['descricao_esfera_administrativa', 'esfera_administrativa']);
        $turnoAtendimento = extrairPrimeiroValor($item, ['descricao_turno_atendimento', 'turno_atendimento']);
        $atendeSus = extrairPrimeiroValor($item, ['estabelecimento_faz_atendimento_ambulatorial_sus', 'atendimento_ambulatorial_sus']);
        $naturezaJuridica = extrairPrimeiroValor($item, ['descricao_natureza_juridica_estabelecimento', 'natureza_juridica']);
        $atualizadoEm = extrairPrimeiroValor($item, ['data_atualizacao', 'updated_at']);
        $latitude = extrairPrimeiroValor($item, ['latitude_estabelecimento_decimo_grau', 'latitude']);
        $longitude = extrairPrimeiroValor($item, ['longitude_estabelecimento_decimo_grau', 'longitude']);
        $leitos = extrairPrimeiroValor($item, ['leitos', 'qtd_leitos', 'qt_leitos', 'quantidade_leitos', 'total_leitos']);
        $endereco = montarEnderecoServicoSaude($item, $contexto);
        $municipio = extrairPrimeiroValor($item, ['municipio', 'nome_municipio', 'cidade']);
        $uf = extrairPrimeiroValor($item, ['uf', 'sigla_uf']);

        if ($municipio === '' && isset($contexto['cidade']) && is_string($contexto['cidade'])) {
            $municipio = $contexto['cidade'];
        }

        if ($uf === '' && isset($contexto['uf']) && is_string($contexto['uf'])) {
            $uf = $contexto['uf'];
        }

        $localidade = implode('/', array_filter([$municipio, $uf], static fn(string $valor): bool => $valor !== ''));
        $coordenadas = implode(', ', array_filter([$latitude, $longitude], static fn(string $valor): bool => $valor !== ''));

        $registro = array_filter([
            'nome' => $nome,
            'razao_social' => $razaoSocial,
            'tipo' => $tipo !== '' ? $tipo : $tipoPadrao,
            'endereco' => $endereco,
            'localidade' => $localidade,
            'telefone' => $telefone,
            'email' => $email,
            'cnpj' => $cnpj,
            'gestao' => $gestao,
            'esfera_administrativa' => $esferaAdministrativa,
            'turno_atendimento' => $turnoAtendimento,
            'atende_sus' => $atendeSus,
            'codigo' => $codigo,
            'codigo_estabelecimento' => $codigoEstabelecimento,
            'leitos' => $leitos,
            'natureza_juridica' => $naturezaJuridica,
            'coordenadas' => $coordenadas,
            'atualizado_em' => $atualizadoEm,
            'descricao' => resumirItemGenerico($item),
        ], static fn(string $valor): bool => $valor !== '');

        if (!isset($registro['nome']) && isset($registro['razao_social'])) {
            $registro['nome'] = $registro['razao_social'];
        }

        if (!isset($registro['nome']) && isset($registro['descricao'])) {
            $registro['nome'] = $registro['descricao'];
        }

        if ($registro !== []) {
            $itens[] = $registro;
        }
    }

    return $itens;
}

function textoPareceUbs(string $texto): bool
{
    $texto = textoParaMaiusculas(normalizarTexto(removerAcentos($texto)));

    if ($texto === '') {
        return false;
    }

    if (preg_match('/\b(UBS|USF|ESF)\b/u', $texto) === 1) {
        return true;
    }

    foreach ([
        'UNIDADE BASICA DE SAUDE',
        'UNIDADE DE SAUDE DA FAMILIA',
        'ESTRATEGIA SAUDE DA FAMILIA',
        'POSTO DE SAUDE',
        'CENTRO DE SAUDE',
    ] as $termo) {
        if (str_contains($texto, $termo)) {
            return true;
        }
    }

    return false;
}

function itemPareceUbs(array $item): bool
{
    $textos = [
        extrairPrimeiroValor($item, [
            'tipo_unidade', 'tipoUnidade', 'descricao_subtipo_unidade', 'subtipo_unidade',
            'categoria', 'natureza_organizacao', 'tipo'
        ]),
        extrairPrimeiroValor($item, [
            'nome_fantasia', 'nomeFantasia', 'nome_estabelecimento', 'estabelecimento',
            'razao_social', 'razaoSocial', 'nome_razao_social', 'nome'
        ]),
        resumirItemGenerico($item),
    ];

    foreach ($textos as $texto) {
        if (textoPareceUbs($texto)) {
            return true;
        }
    }

    return false;
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

function lerEstabelecimentosJsonLocal(string $cidade): array
{
    $nomeArquivo = textoParaMinusculas(removerAcentos(normalizarTexto($cidade)));
    $nomeArquivo = preg_replace('/\s+/', '_', $nomeArquivo) ?? $nomeArquivo;
    $nomeArquivo = preg_replace('/[^a-z0-9_]/', '', $nomeArquivo) ?? $nomeArquivo;
    $caminho = __DIR__ . '/' . $nomeArquivo . '_cidade.json';

    if (!is_file($caminho)) {
        return ['erro' => 'Arquivo local não encontrado.'];
    }

    $conteudo = @file_get_contents($caminho);
    if ($conteudo === false) {
        return ['erro' => 'Falha ao ler arquivo local.'];
    }

    $dados = json_decode($conteudo, true);
    if (!is_array($dados)) {
        return ['erro' => 'Arquivo local com formato inválido.'];
    }

    if (isset($dados['estabelecimentos']) && is_array($dados['estabelecimentos'])) {
        return $dados['estabelecimentos'];
    }

    $lista = encontrarListaDeObjetos($dados);
    return $lista !== [] ? $lista : ['erro' => 'Estrutura do arquivo local não reconhecida.'];
}

function itemPareceHospital(array $item): bool
{
    // 5=Hospital Geral, 7=Hospital Especializado, 15=Unidade Mista, 20=Pronto-socorro Geral,
    // 21=Pronto-socorro Especializado, 22=Pronto-socorro Trauma/Ortopedia, 61=Centro de Parto Normal,
    // 62=Hospital/Dia, 73=Pronto Atendimento
    $codigosHospital = [5, 7, 15, 20, 21, 22, 61, 62, 73];
    if (isset($item['codigo_tipo_unidade']) && in_array((int) $item['codigo_tipo_unidade'], $codigosHospital, true)) {
        return true;
    }

    $textos = [
        extrairPrimeiroValor($item, [
            'tipo_unidade', 'tipoUnidade', 'descricao_subtipo_unidade', 'subtipo_unidade',
            'categoria', 'natureza_organizacao', 'tipo'
        ]),
        extrairPrimeiroValor($item, [
            'nome_fantasia', 'nomeFantasia', 'nome_estabelecimento', 'estabelecimento',
            'razao_social', 'razaoSocial', 'nome_razao_social', 'nome'
        ]),
    ];

    foreach ($textos as $texto) {
        $texto = textoParaMaiusculas(normalizarTexto(removerAcentos($texto)));
        foreach (['HOSPITAL', 'PRONTO SOCORRO', 'PRONTO-SOCORRO', 'UPA', 'UNIDADE MISTA'] as $termo) {
            if (str_contains($texto, $termo)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Busca uma página do endpoint CNES de estabelecimentos e retorna o total real
 * informado pela API junto com os itens da página.
 *
 * Retorna ['total' => int|null, 'itens' => array] em caso de sucesso,
 * ou ['erro' => string, ...] em caso de falha.
 */
function buscarPaginaEstabelecimentos(array $parametros): array
{
    $url = construirUrlApiSaude('/cnes/estabelecimentos', $parametros);
    $dados = chamarApi($url);

    if (isset($dados['erro'])) {
        return $dados;
    }

    $mensagemErro = extrairMensagemErroApi($dados);
    if ($mensagemErro !== '') {
        return [
            'erro' => $mensagemErro,
            'url' => sanitizarUrlParaDebug($url),
            'json_bruto' => $dados,
        ];
    }

    $total = isset($dados['total']) && is_numeric($dados['total']) ? (int) $dados['total'] : null;
    $itens = encontrarListaDeObjetos($dados);

    return ['total' => $total, 'itens' => $itens];
}

function obterEstabelecimentosPorMunicipio(string $codigoMunicipio, string $uf = '', string $cidade = ''): array
{
    $parametrosBase = [
        'codigo_uf' => obterCodigoUf($uf),
        'codigo_municipio' => normalizarCodigoMunicipio($codigoMunicipio),
        'status' => 1,
    ];

    $todos = [];
    $offset = 0;
    $totalApi = null;
    $erroApi = null;

    do {
        $pagina = buscarPaginaEstabelecimentos(array_merge($parametrosBase, [
            'limit' => CNES_ESTABELECIMENTOS_POR_PAGINA,
            'offset' => $offset,
        ]));

        if (isset($pagina['erro'])) {
            $erroApi = $pagina;
            break;
        }

        if ($totalApi === null) {
            $totalApi = $pagina['total'];
        }

        $todos = array_merge($todos, $pagina['itens']);
        $offset += CNES_ESTABELECIMENTOS_POR_PAGINA;

    } while (
        count($pagina['itens']) === CNES_ESTABELECIMENTOS_POR_PAGINA
        && count($todos) < CNES_ESTABELECIMENTOS_MAX_TOTAL
        && ($totalApi === null || count($todos) < $totalApi)
    );

    if ($todos === [] && $erroApi !== null && $cidade !== '') {
        $lista = lerEstabelecimentosJsonLocal($cidade);
        if (isset($lista['erro'])) {
            return $lista;
        }
        $todos = $lista;
    } elseif ($todos === [] && $erroApi !== null) {
        return $erroApi;
    }

    return normalizarListaServicosSaude($todos, 'Estabelecimento de saúde', [
        'uf' => $uf,
        'cidade' => $cidade,
    ]);
}

function obterHospitaisPorMunicipio(string $uf, string $cidade, string $codigoMunicipio = ''): array
{
    $lista = obterListaApiSaude('/assistencia-a-saude/hospitais-e-leitos', [
        'UF' => $uf,
        'municipio' => $cidade,
        'limit' => 20,
        'offset' => 0,
    ]);

    if (isset($lista['erro']) && $cidade !== '') {
        $locais = lerEstabelecimentosJsonLocal($cidade);
        if (!isset($locais['erro'])) {
            $lista = array_values(array_filter($locais, static fn($item): bool => is_array($item) && itemPareceHospital($item)));
        }
    }

    return isset($lista['erro']) ? $lista : normalizarListaServicosSaude($lista, 'Hospital', [
        'uf' => $uf,
        'cidade' => $cidade,
    ]);
}

function obterUbsPorMunicipio(string $uf, string $cidade, string $codigoMunicipio = ''): array
{
    $lista = obterListaApiSaude('/assistencia-a-saude/unidades-basicas-de-saude', [
        'UF' => $uf,
        'municipio' => $cidade,
        'limit' => 20,
        'offset' => 0,
    ]);

    if (!isset($lista['erro'])) {
        return normalizarListaServicosSaude($lista, 'UBS', [
            'uf' => $uf,
            'cidade' => $cidade,
        ]);
    }

    $codigoUf = obterCodigoUf($uf);
    $codigoMunicipioNorm = normalizarCodigoMunicipio($codigoMunicipio);

    if ($codigoUf !== '' && $codigoMunicipioNorm !== '') {
        $fallback = obterListaApiSaude('/cnes/estabelecimentos', [
            'codigo_uf' => $codigoUf,
            'codigo_municipio' => $codigoMunicipioNorm,
            'status' => 1,
            'limit' => 20,
            'offset' => 0,
        ]);

        if (!isset($fallback['erro'])) {
            $ubs = array_values(array_filter($fallback, static fn($item): bool => is_array($item) && itemPareceUbs($item)));
            return normalizarListaServicosSaude($ubs, 'UBS', [
                'uf' => $uf,
                'cidade' => $cidade,
            ]);
        }
    }

    if ($cidade !== '') {
        $locais = lerEstabelecimentosJsonLocal($cidade);
        if (!isset($locais['erro'])) {
            $ubs = array_values(array_filter($locais, static fn($item): bool => is_array($item) && itemPareceUbs($item)));
            return normalizarListaServicosSaude($ubs, 'UBS', [
                'uf' => $uf,
                'cidade' => $cidade,
            ]);
        }
    }

    return $lista;
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
