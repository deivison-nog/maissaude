# maissaude

Projeto PHP simples para fluxo **Estado → Cidade → Resultado** usando a API de Dados Abertos da Saúde, com fallback para API do IBGE quando necessário.

## Arquivos principais

- `index.php`: página principal com seleção de estado/cidade.
- `buscar.php`: endpoint JSON para cidades e resultado por município.
- `api.php`: biblioteca de integração com a API externa.

## Executar localmente

Com PHP 8+:

```bash
cd <diretorio-do-projeto>
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