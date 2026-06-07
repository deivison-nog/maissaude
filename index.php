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
        body { font-family: Arial, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; }
        h1 { font-size: 1.5rem; }
        form { display: grid; gap: 0.75rem; margin-bottom: 1rem; }
        label { font-weight: 600; }
        select, button { padding: 0.5rem; font-size: 1rem; }
        .muted { color: #666; }
        pre { background: #f6f8fa; padding: 0.75rem; border-radius: 8px; overflow-x: auto; }
        .error { color: #b00020; font-weight: 600; }
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

    <button type="submit" disabled id="btn-consultar">Consultar resultado</button>
</form>

<p id="status" class="muted"></p>
<p id="erro" class="error"></p>
<pre id="resultado">Nenhum resultado ainda.</pre>

<script>
const ufSelect = document.getElementById('uf');
const cidadeSelect = document.getElementById('cidade');
const btnConsultar = document.getElementById('btn-consultar');
const statusEl = document.getElementById('status');
const erroEl = document.getElementById('erro');
const resultadoEl = document.getElementById('resultado');

function resetResultado(msg = 'Nenhum resultado ainda.') {
    erroEl.textContent = '';
    resultadoEl.textContent = msg;
}

ufSelect.addEventListener('change', async () => {
    const uf = ufSelect.value;
    cidadeSelect.innerHTML = '<option value="">Selecione...</option>';
    cidadeSelect.disabled = true;
    btnConsultar.disabled = true;
    resetResultado();

    if (!uf) {
        statusEl.textContent = 'Selecione um estado para carregar as cidades.';
        return;
    }

    statusEl.textContent = 'Carregando cidades...';

    try {
        const response = await fetch(`buscar.php?action=cidades&uf=${encodeURIComponent(uf)}`);
        const data = await response.json();

        if (!response.ok || data.erro) {
            throw new Error(data.erro || 'Falha ao carregar cidades.');
        }

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
    resultadoEl.textContent = 'Aguarde...';

    const uf = ufSelect.value;
    const cidade = cidadeSelect.value;

    try {
        const response = await fetch(`buscar.php?action=resultado&uf=${encodeURIComponent(uf)}&cidade=${encodeURIComponent(cidade)}`);
        const data = await response.json();

        if (!response.ok || data.erro) {
            throw new Error(data.erro || 'Falha ao consultar resultado.');
        }

        resultadoEl.textContent = JSON.stringify(data.resultado, null, 2);
        statusEl.textContent = 'Consulta concluída.';
    } catch (error) {
        erroEl.textContent = error.message;
        statusEl.textContent = '';
        resultadoEl.textContent = 'Nenhum resultado disponível.';
    }
});
</script>
</body>
</html>
