# Meta Ads - Anuncios em massa

## Variaveis de ambiente
- `META_APP_ID`
- `META_APP_SECRET`
- `META_REDIRECT_URI` (ex.: `http://localhost/meta/callback`)
- `META_GRAPH_VERSION` (default `v20.0`)
- `META_OAUTH_SCOPES` (default inclui ads, business e pages)
- `META_FONT_PATH` (caminho para fonte TTF usada no texto da imagem)

## Passos basicos
1) Instale as dependencias do Filament e rode `composer install`/`composer update`.
2) Rode as migrations para criar tabelas (`php artisan migrate`).
3) Garanta que o queue worker esta ativo (`php artisan queue:work`).
4) Acesse `/admin/meta-ads` e clique em "Conectar Meta".
5) Preencha o formulario e dispare o lote.

## Observacoes
- O texto `{cidade}` sera substituido automaticamente pelo nome da cidade.
- Se nao houver fonte TTF configurada, o texto sera desenhado com fonte basica do GD.
