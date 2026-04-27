# HEX_FixDuplicateMissingMedia

Contorna erro ao **duplicar produto** no Admin quando a galeria referencia arquivos inexistentes em `pub/media/catalog/product`.

## Sintoma

- Erro ao duplicar/salvar: `We couldn't copy file %1. Please delete media with non-existing images and try again.`

## Como funciona

- Plugin **adminhtml** em `Magento\Catalog\Model\Product\Gallery\CreateHandler`.
- Durante duplicação (`getIsDuplicate()==true`), remove do payload de `media_gallery` as imagens cujo arquivo não existe.
- Se `image/small_image/thumbnail` apontarem para arquivo ausente, define `no_selection` para evitar referência quebrada no duplicado.

## Instalação

- `php bin/magento module:enable HEX_FixDuplicateMissingMedia`
- `php bin/magento setup:upgrade`
- `php bin/magento setup:di:compile`
- `php bin/magento cache:flush`

