<?php
require_once __DIR__ . '/api.php';
$estados = obterEstadosFixos();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Consulta Saúde por Estado e Cidade</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 980px; margin: 2rem auto; padding: 0 1rem 3rem; }
        h1 { font-size: 1.75rem; margin-bottom: 0.5rem; }
        h2 { margin: 0 0 0.75rem; font-size: 1.2rem; }
        h3 { margin: 1rem 0 0.5rem; font-size: 1rem; }
        form { display: grid; gap: 0.75rem; margin-bottom: 1rem; }
        label { font-weight: 600; }
        select, button { padding: 0.5rem; font-size: 1rem; }
        .muted { color: #666; }
        .error { color: #b00020; font-weight: 600; }
        .grid { display: grid; gap: 1rem; }
        @media (min-width: 860px) {
            .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        .card, pre { background: #f6f8fa; padding: 0.9rem; border-radius: 10px; border: 1px solid #d8dee4; }
        pre { overflow-x: auto; margin: 0; }
        ul { margin: 0.5rem 0 0; padding-left: 1.25rem; }
        li { margin-bottom: 0.75rem; }
        details { margin-top: 0.75rem; }
        details summary { cursor: pointer; font-weight: 600; }
        .section-hidden { display: none; }
        .service-details { display: grid; gap: 0.2rem; margin-top: 0.35rem; }
        .service-details span { display: block; }
    </style>
</head>
<body>
<h1>Fluxo Estado → Cidade → Resultado</h1>
<p class="muted">Selecione um estado, carregue as cidades e consulte os detalhes do município.</p>

<form id="consulta-form">
    <div>
        <label for="uf">Estado (UF)</label><br>
        <select id="uf" name="uf" required>
            <option value="">Selecione...</option>
            <?php foreach ($estados as $estado): ?>
                <option value="<?= htmlspecialchars($estado, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($estado, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label for="cidade">Cidade</label><br>
        <select id="cidade" name="cidade" required disabled>
            <option value="">Selecione um estado primeiro...</option>
        </select>
    </div>

    <button type="submit" disabled id="btn-consultar" aria-describedby="status">Consultar resultado</button>
</form>

<p id="status" class="muted" aria-live="polite"></p>
<p id="erro" class="error"></p>

<section id="painel-resultado" class="grid section-hidden">
    <section class="card">
        <h2>Visão geral</h2>
        <pre id="resultado">Nenhum resultado ainda.</pre>
        <div id="resumo-mais-medicos" class="muted" style="margin-top: 0.75rem;"></div>
    </section>

    <section class="card">
        <h2>Estabelecimentos</h2>
        <p id="status-estabelecimentos" class="muted">Nenhuma consulta realizada.</p>
        <div id="bloco-estabelecimentos"></div>
    </section>

    <section class="card" style="grid-column: 1 / -1;">
        <h2>Epidemiologia</h2>
        <p id="status-epidemiologia" class="muted">Nenhuma consulta realizada.</p>
        <div id="bloco-epidemiologia"></div>
    </section>
</section>

<script>
const ufSelect = document.getElementById('uf');
const cidadeSelect = document.getElementById('cidade');
const btnConsultar = document.getElementById('btn-consultar');
const statusEl = document.getElementById('status');
const erroEl = document.getElementById('erro');
const resultadoEl = document.getElementById('resultado');
const painelResultadoEl = document.getElementById('painel-resultado');
const statusEstabelecimentosEl = document.getElementById('status-estabelecimentos');
const blocoEstabelecimentosEl = document.getElementById('bloco-estabelecimentos');
const statusEpidemiologiaEl = document.getElementById('status-epidemiologia');
const blocoEpidemiologiaEl = document.getElementById('bloco-epidemiologia');
const resumoMaisMedicosEl = document.getElementById('resumo-mais-medicos');
const PREVIEW_ITEMS_LIMIT = 5;
const paramsIniciais = new URLSearchParams(window.location.search);

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function resetCidadeSelect(textoPlaceholder) {
    cidadeSelect.options.length = 0;
    const option = document.createElement('option');
    option.value = '';
    option.textContent = textoPlaceholder;
    cidadeSelect.appendChild(option);
}

function limparPaineis() {
    painelResultadoEl.classList.add('section-hidden');
    resultadoEl.textContent = 'Nenhum resultado ainda.';
    statusEstabelecimentosEl.textContent = 'Nenhuma consulta realizada.';
    blocoEstabelecimentosEl.innerHTML = '';
    statusEpidemiologiaEl.textContent = 'Nenhuma consulta realizada.';
    blocoEpidemiologiaEl.innerHTML = '';
    resumoMaisMedicosEl.textContent = '';
}

function resetResultado(msg = 'Nenhum resultado ainda.') {
    erroEl.textContent = '';
    limparPaineis();
    resultadoEl.textContent = msg;
}

function atualizarUrlConsulta(uf = '', cidade = '') {
    const url = new URL(window.location.href);

    if (uf) {
        url.searchParams.set('uf', uf);
    } else {
        url.searchParams.delete('uf');
    }

    if (cidade) {
        url.searchParams.set('cidade', cidade);
    } else {
        url.searchParams.delete('cidade');
    }

    window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
}

function montarListaHtml(itens, formatter, emptyMessage) {
    if (!Array.isArray(itens) || itens.length === 0) {
        return `<p class="muted">${escapeHtml(emptyMessage)}</p>`;
    }

    const preview = itens.slice(0, PREVIEW_ITEMS_LIMIT).map(formatter).join('');
    const restante = itens.slice(PREVIEW_ITEMS_LIMIT).map(formatter).join('');

    if (!restante) {
        return `<ul>${preview}</ul>`;
    }

    return `<ul>${preview}</ul><details><summary>Ver todos (${itens.length})</summary><ul>${restante}</ul></details>`;
}

function formatarServico(item) {
    const nome = item.nome || item.descricao || 'Registro';
    const detalhes = [
        ['Tipo', item.tipo],
        ['Razão social', item.razao_social && item.razao_social !== nome ? item.razao_social : ''],
        ['CNPJ', item.cnpj],
        ['Endereço', item.endereco],
        ['Localidade', item.localidade],
        ['Telefone', item.telefone],
        ['E-mail', item.email],
        ['Gestão', item.gestao],
        ['Esfera administrativa', item.esfera_administrativa],
        ['Turno', item.turno_atendimento],
        ['Atendimento SUS', item.atende_sus],
        ['CNES', item.codigo],
        ['Código do estabelecimento', item.codigo_estabelecimento],
        ['Leitos', item.leitos],
        ['Natureza jurídica', item.natureza_juridica],
        ['Coordenadas', item.coordenadas],
        ['Atualizado em', item.atualizado_em],
        ['Detalhes', item.descricao && item.descricao !== nome ? item.descricao : ''],
    ].filter(([, valor]) => Boolean(valor));

    const detalhesHtml = detalhes.length
        ? `<div class="service-details muted">${detalhes.map(([rotulo, valor]) => `<span><strong>${escapeHtml(rotulo)}:</strong> ${escapeHtml(valor)}</span>`).join('')}</div>`
        : '';

    return `<li><strong>${escapeHtml(nome)}</strong>${detalhesHtml}</li>`;
}

function formatarEventoEpidemiologico(item) {
    const titulo = item.titulo || item.nome || 'Registro epidemiológico';
    const detalhes = [
        item.periodo ? `Período: ${item.periodo}` : '',
        item.casos ? `Casos: ${item.casos}` : '',
        item.observacao || '',
    ].filter(Boolean);

    return `<li><strong>${escapeHtml(titulo)}</strong>${detalhes.length ? `<br><span class="muted">${escapeHtml(detalhes.join(' • '))}</span>` : ''}</li>`;
}

async function buscarJson(url, errorMessage) {
    let response;

    try {
        response = await fetch(url);
    } catch (error) {
        console.error('[maissaude] Falha de rede ao consultar API.', {
            url,
            erro: error,
        });
        throw new Error(`${errorMessage} (falha de rede).`);
    }

    const respostaBruta = await response.text();
    let data;

    try {
        data = respostaBruta ? JSON.parse(respostaBruta) : {};
    } catch (error) {
        console.error('[maissaude] Resposta JSON inválida.', {
            url,
            status: response.status,
            statusText: response.statusText,
            respostaBruta,
            erro: error,
        });
        throw new Error(`Resposta JSON inválida: ${error.message}`);
    }

    if (!response.ok || data.erro) {
        console.error('[maissaude] Erro retornado pela API.', {
            url,
            status: response.status,
            statusText: response.statusText,
            payload: data,
        });
        throw new Error(data.erro || errorMessage);
    }

    return data;
}

async function carregarBlocosComplementares(uf, cidade) {
    statusEstabelecimentosEl.textContent = 'Carregando estabelecimentos, hospitais e UBS...';
    statusEpidemiologiaEl.textContent = 'Carregando dados epidemiológicos...';
    blocoEstabelecimentosEl.innerHTML = '';
    blocoEpidemiologiaEl.innerHTML = '';
    resumoMaisMedicosEl.textContent = 'Carregando programa Mais Médicos...';

    const urlsEstabelecimentos = [
        ['Estabelecimentos de saúde', `buscar.php?action=estabelecimentos&uf=${encodeURIComponent(uf)}&cidade=${encodeURIComponent(cidade)}`],
        ['Hospitais e leitos', `buscar.php?action=hospitais&uf=${encodeURIComponent(uf)}&cidade=${encodeURIComponent(cidade)}`],
        ['UBS', `buscar.php?action=ubs&uf=${encodeURIComponent(uf)}&cidade=${encodeURIComponent(cidade)}`],
    ];
    const urlsEpidemiologia = [
        ['Dengue', `buscar.php?action=arboviroses&doenca=dengue&uf=${encodeURIComponent(uf)}&cidade=${encodeURIComponent(cidade)}`],
        ['Zika vírus', `buscar.php?action=arboviroses&doenca=zikavirus&uf=${encodeURIComponent(uf)}&cidade=${encodeURIComponent(cidade)}`],
        ['Chikungunya', `buscar.php?action=arboviroses&doenca=chikungunya&uf=${encodeURIComponent(uf)}&cidade=${encodeURIComponent(cidade)}`],
    ];

    const [estabelecimentosResults, epidemiologiaResults, maisMedicosResult] = await Promise.all([
        Promise.allSettled(urlsEstabelecimentos.map(([, url]) => buscarJson(url, 'Falha ao carregar estabelecimentos.'))),
        Promise.allSettled(urlsEpidemiologia.map(([, url]) => buscarJson(url, 'Falha ao carregar arboviroses.'))),
        buscarJson(`buscar.php?action=mais-medicos&uf=${encodeURIComponent(uf)}&cidade=${encodeURIComponent(cidade)}`, 'Falha ao carregar Mais Médicos.')
            .then((data) => ({ status: 'fulfilled', value: data }))
            .catch((error) => ({ status: 'rejected', reason: error })),
    ]);

    blocoEstabelecimentosEl.innerHTML = urlsEstabelecimentos.map(([titulo], index) => {
        const result = estabelecimentosResults[index];
        if (result.status !== 'fulfilled') {
            return `<section><h3>${escapeHtml(titulo)}</h3><p class="error">${escapeHtml(result.reason.message)}</p></section>`;
        }

        const { itens = [], total = 0 } = result.value;
        return `<section><h3>${escapeHtml(titulo)} (${total})</h3>${montarListaHtml(itens, formatarServico, `Nenhum registro encontrado em ${titulo.toLowerCase()}.`)}</section>`;
    }).join('');

    statusEstabelecimentosEl.textContent = 'Consulta de estabelecimentos concluída.';

    blocoEpidemiologiaEl.innerHTML = urlsEpidemiologia.map(([titulo], index) => {
        const result = epidemiologiaResults[index];
        if (result.status !== 'fulfilled') {
            return `<section><h3>${escapeHtml(titulo)}</h3><p class="error">${escapeHtml(result.reason.message)}</p></section>`;
        }

        const { itens = [], total = 0 } = result.value;
        return `<section><h3>${escapeHtml(titulo)} (${total})</h3>${montarListaHtml(itens, formatarEventoEpidemiologico, `Nenhum registro encontrado para ${titulo.toLowerCase()}.`)}</section>`;
    }).join('');

    statusEpidemiologiaEl.textContent = 'Consulta epidemiológica concluída.';

    if (maisMedicosResult.status === 'fulfilled') {
        const total = maisMedicosResult.value.total || 0;
        resumoMaisMedicosEl.textContent = total > 0
            ? `Mais Médicos: ${total} registro(s) encontrado(s) para o município.`
            : 'Mais Médicos: nenhum registro encontrado para o município.';
    } else {
        resumoMaisMedicosEl.textContent = `Mais Médicos: ${maisMedicosResult.reason.message}`;
    }
}

async function carregarCidades(uf, cidadeSelecionada = '') {
    resetCidadeSelect('Selecione...');
    cidadeSelect.disabled = true;
    btnConsultar.disabled = true;
    resetResultado();

    if (!uf) {
        statusEl.textContent = 'Selecione um estado para carregar as cidades.';
        atualizarUrlConsulta();
        return;
    }

    atualizarUrlConsulta(uf);
    statusEl.textContent = 'Carregando cidades...';

    try {
        const data = await buscarJson(`buscar.php?action=cidades&uf=${encodeURIComponent(uf)}`, 'Falha ao carregar cidades.');

        if (!Array.isArray(data.cidades) || data.cidades.length === 0) {
            statusEl.textContent = 'Nenhuma cidade encontrada para a UF selecionada.';
            return;
        }

        data.cidades.forEach((cidade) => {
            const option = document.createElement('option');
            option.value = cidade;
            option.textContent = cidade;
            cidadeSelect.appendChild(option);
        });

        cidadeSelect.disabled = false;
        if (cidadeSelecionada && data.cidades.includes(cidadeSelecionada)) {
            cidadeSelect.value = cidadeSelecionada;
            btnConsultar.disabled = false;
            atualizarUrlConsulta(uf, cidadeSelecionada);
        }
        statusEl.textContent = `${data.cidades.length} cidades carregadas.`;
    } catch (error) {
        console.error('[maissaude] Falha ao carregar cidades.', {
            uf,
            erro: error,
        });
        erroEl.textContent = error.message;
        statusEl.textContent = '';
    }
}

async function consultarResultado(uf, cidade) {
    atualizarUrlConsulta(uf, cidade);
    erroEl.textContent = '';
    statusEl.textContent = 'Buscando resultado...';
    resetResultado('Aguarde...');

    try {
        const data = await buscarJson(
            `buscar.php?action=resultado&uf=${encodeURIComponent(uf)}&cidade=${encodeURIComponent(cidade)}`,
            'Falha ao consultar resultado.'
        );

        painelResultadoEl.classList.remove('section-hidden');
        resultadoEl.textContent = JSON.stringify(data.resultado, null, 2);
        statusEl.textContent = 'Consulta concluída. Carregando dados complementares...';
        await carregarBlocosComplementares(uf, cidade);
        statusEl.textContent = 'Consulta concluída.';
    } catch (error) {
        erroEl.textContent = error.message;
        statusEl.textContent = '';
        limparPaineis();
        resultadoEl.textContent = 'Nenhum resultado disponível.';
    }
}

ufSelect.addEventListener('change', async () => {
    await carregarCidades(ufSelect.value);
});

cidadeSelect.addEventListener('change', () => {
    btnConsultar.disabled = cidadeSelect.value === '';
    atualizarUrlConsulta(ufSelect.value, cidadeSelect.value);
});

document.getElementById('consulta-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    await consultarResultado(ufSelect.value, cidadeSelect.value);
});

(async () => {
    const ufInicial = (paramsIniciais.get('uf') || '').toUpperCase();
    const cidadeInicial = paramsIniciais.get('cidade') || '';

    if (!ufInicial) {
        return;
    }

    if (![...ufSelect.options].some((option) => option.value === ufInicial)) {
        atualizarUrlConsulta();
        return;
    }

    ufSelect.value = ufInicial;
    await carregarCidades(ufInicial, cidadeInicial);

    if (cidadeInicial && cidadeSelect.value === cidadeInicial) {
        await consultarResultado(ufInicial, cidadeInicial);
    }
})();
</script>
</body>
</html>
