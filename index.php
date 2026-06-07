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

<p id="status" class="muted"></p>
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

function montarListaHtml(itens, formatter, emptyMessage) {
    if (!Array.isArray(itens) || itens.length === 0) {
        return `<p class="muted">${escapeHtml(emptyMessage)}</p>`;
    }

    const preview = itens.slice(0, 5).map(formatter).join('');
    const restante = itens.slice(5).map(formatter).join('');

    if (!restante) {
        return `<ul>${preview}</ul>`;
    }

    return `<ul>${preview}</ul><details><summary>Ver todos (${itens.length})</summary><ul>${restante}</ul></details>`;
}

function formatarServico(item) {
    const nome = item.nome || item.descricao || 'Registro';
    const detalhes = [
        item.tipo,
        item.endereco,
        item.telefone ? `Telefone: ${item.telefone}` : '',
        item.leitos ? `Leitos: ${item.leitos}` : '',
        item.codigo ? `Código: ${item.codigo}` : '',
    ].filter(Boolean);

    return `<li><strong>${escapeHtml(nome)}</strong>${detalhes.length ? `<br><span class="muted">${escapeHtml(detalhes.join(' • '))}</span>` : ''}</li>`;
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
    const response = await fetch(url);
    const data = await response.json();

    if (!response.ok || data.erro) {
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

ufSelect.addEventListener('change', async () => {
    const uf = ufSelect.value;
    resetCidadeSelect('Selecione...');
    cidadeSelect.disabled = true;
    btnConsultar.disabled = true;
    resetResultado();

    if (!uf) {
        statusEl.textContent = 'Selecione um estado para carregar as cidades.';
        return;
    }

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
        btnConsultar.disabled = false;
        statusEl.textContent = `${data.cidades.length} cidades carregadas.`;
    } catch (error) {
        erroEl.textContent = error.message;
        statusEl.textContent = '';
    }
});

document.getElementById('consulta-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    erroEl.textContent = '';
    statusEl.textContent = 'Buscando resultado...';
    resetResultado('Aguarde...');

    const uf = ufSelect.value;
    const cidade = cidadeSelect.value;

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
});
</script>
</body>
</html>
