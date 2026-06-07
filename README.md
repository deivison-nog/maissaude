# maissaude

Projeto PHP simples para fluxo **Estado → Cidade → Resultado** usando a API de Dados Abertos da Saúde.

## Arquivos principais

- `/tmp/workspace/deivison-nog/maissaude/index.php`: página principal com seleção de estado/cidade.
- `/tmp/workspace/deivison-nog/maissaude/buscar.php`: endpoint JSON para cidades e resultado por município.
- `/tmp/workspace/deivison-nog/maissaude/api.php`: biblioteca de integração com a API externa e endpoint JSON direto.

## Executar localmente

Com PHP 8+:

```bash
cd /tmp/workspace/deivison-nog/maissaude
php -S localhost:8000
```

Abra no navegador:

```
http://localhost:8000/index.php
```

## Endpoints úteis

- `GET /buscar.php?action=estados`
- `GET /buscar.php?action=cidades&uf=SP`
- `GET /buscar.php?action=resultado&uf=SP&cidade=São%20Paulo`
- `GET /api.php?uf=SP` (retorna estrutura completa para a UF)