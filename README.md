# Khipu para WHMCS

Módulo oficial de [Khipu](https://khipu.com) para cobrar facturas de WHMCS por
transferencia bancaria, con reversas (devolución del dinero al cliente) desde la factura,
usando la billetera de reversas de Khipu.

- **Módulo:** `khipu` · **Versión:** 3.0.0 · **API de Khipu:** v3
- **Compatibilidad:** WHMCS 8.13 (LTS) y 9.0

## Documentación

| Para | Documento |
|---|---|
| Instalar, configurar, operar, reversar y actualizar | [docs/manual-comercio.md](docs/manual-comercio.md) |
| Arquitectura, flujos, API, desarrollo y empaquetado (en inglés) | [docs/technical-manual.md](docs/technical-manual.md) |

## Instalación rápida

1. Descarga `whmcs-khipu.zip` desde la
   [última versión publicada](https://github.com/khipu/whmcs-khipu/releases/latest).
2. Descomprímelo y sube la carpeta `modules/` a la raíz de tu WHMCS.
3. En **Configuración → Aplicaciones e integraciones**, busca **Khipu**, actívalo y completa
   **API Key**, **ID Cobrador** y **Llave secreta** (están en tu cuenta Khipu, en
   *Opciones de la cuenta → Para integrar Khipu a tu sitio web*).

Requiere que WHMCS sea alcanzable por HTTPS desde Internet: Khipu confirma cada pago con
una notificación a `https://<tu-whmcs>/modules/gateways/callback/khipu.php`.

¿Vienes de la versión 2.x? Lee
[Actualizar desde la versión 2.x](docs/manual-comercio.md#actualizar-desde-la-versión-2x):
hay que agregar la API Key.

## Desarrollo

```bash
docker run --rm -v "$PWD":/app -w /app -u "$(id -u):$(id -g)" composer:2 install
docker run --rm -v "$PWD":/app -w /app -u "$(id -u):$(id -g)" php:8.3-cli php vendor/bin/phpunit
./package.sh           # genera dist/whmcs-khipu.zip
```

WHMCS local con Docker (8.13 y 9.0, módulo montado desde el repositorio): ver
[dev/README.md](dev/README.md). Requiere una licencia de WHMCS.

## Soporte

**soporte@khipu.com**: indica versión del módulo y de WHMCS, número de factura y, en
reversas, el `payment_id` que aparece en el registro de pasarela. Nunca envíes tu Llave secreta
ni tu API Key.
