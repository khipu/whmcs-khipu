# Khipu en WHMCS

Guía para instalar, configurar, operar y actualizar Khipu como medio de pago en WHMCS.

- **Módulo:** `khipu` · **Versión:** 3.0.0 · **API de Khipu:** v3
- **Compatibilidad:** WHMCS 8.13 (LTS) y 9.0

---

## Antes de empezar

Necesitas:

- Una cuenta habilitada para cobrar en [khipu.com](https://khipu.com/login/auth).
- WHMCS **8.13** o **9.0**, con la extensión **cURL** de PHP (WHMCS ya la exige).
- WHMCS publicado en **HTTPS** y accesible desde Internet: Khipu confirma cada pago
  llamando a `https://<tu-whmcs>/modules/gateways/callback/khipu.php`. Si esa URL no es
  alcanzable, las facturas quedan impagas aunque el cliente haya pagado.
- La **URL del sistema WHMCS** (*WHMCS System URL*, en **Configuración → Ajustes →
  Configuración general**) con `https://` y tu dominio público: el módulo arma con ella
  las direcciones que le entrega a Khipu.
- El archivo `whmcs-khipu.zip` de la
  [última versión publicada](https://github.com/khipu/whmcs-khipu/releases/latest).

De tu cuenta Khipu vas a ocupar tres datos, todos en **Opciones de la cuenta → Para
integrar Khipu a tu sitio web**:

| Dato | Para qué lo usa el módulo |
|---|---|
| **API Key** | Autentica las llamadas a Khipu: crear pagos, confirmarlos y hacer reversas. Se crea en esa misma sección. |
| **ID Cobrador** | Comprueba que cada pago notificado sea de tu cuenta. |
| **Llave secreta** | Verifica la firma de las notificaciones de Khipu. |

Los tres son obligatorios. Trátalos como credenciales: no los compartas ni los pegues en
tickets, correos ni capturas de pantalla.

---

## Instalación

1. Descarga `whmcs-khipu.zip` desde la
   [última versión publicada](https://github.com/khipu/whmcs-khipu/releases/latest).
2. Descomprímelo. Adentro hay una carpeta `modules/`.
3. Sube esa carpeta `modules/` a la **raíz de tu WHMCS** (donde está `configuration.php`),
   fusionándola con la que ya existe. Deben quedar estos archivos:

   ```
   modules/gateways/khipu.php
   modules/gateways/khipu/                      (varios archivos .php)
   modules/gateways/callback/khipu.php
   modules/gateways/callback/khipu_redirect.php
   ```

## Configuración

Los nombres del administrador de WHMCS van en español y, entre paréntesis, en inglés: son
los mismos en 8.13 y 9.0.

1. En el administrador de WHMCS, abre **Configuración → Aplicaciones e integraciones**
   (*Configuration → Apps & Integrations*).
2. En **Buscar** (*Search*) escribe «Khipu». Si no aparece, está en **Navegar**
   (*Browse*) → pagos, en **Aplicaciones adicionales** (*Additional apps*), donde WHMCS
   lista los módulos que no son de su catálogo.
3. Abre **Khipu** y actívalo. Si después quieres cambiar algo, la configuración queda en
   **Configuración → Ajustes → Pasarelas de pago** (*Configuration → System Settings →
   Payment Gateways*).
4. Completa:
   - **Mostrar en el formulario de pedido** (*Show on Order Form*): márcalo para ofrecer
     Khipu en el carro de compras.
   - **Nombre a mostrar** (*Display Name*): el nombre que verá el cliente, por ejemplo
     *Transferencia bancaria con Khipu*.
   - **API Key**, **ID Cobrador** y **Llave secreta**: los de tu cuenta Khipu.
   - **Convertir a para procesar** (*Convert To For Processing*): solo si facturas en una
     moneda que tu cuenta Khipu no cobra (ver [Monedas](#monedas)).
5. Guarda.

> **Verifica la instalación con un pago de prueba de monto bajo.** Es la forma más rápida
> de confirmar que tu servidor recibe la notificación de Khipu y que la factura queda
> **Pagada**.

---

## Cómo se paga una factura

1. El cliente abre su factura (o termina un pedido) y presiona el botón de Khipu.
2. WHMCS le pide el pago a Khipu por el **saldo pendiente** de la factura y lleva al
   cliente a Khipu.
3. El cliente paga con su banco.
4. Khipu notifica el pago a tu WHMCS. El módulo verifica la firma, consulta el pago a
   Khipu para confirmar que está pagado y registra el pago en la factura. La transacción
   queda con el **ID de pago de Khipu** (`payment_id`), que es lo que después permite
   reversarlo.
5. El cliente vuelve a su factura.

**La factura queda pagada con la notificación de Khipu, no con el regreso del cliente.**
Si un cliente pagó y la factura sigue impaga, tu servidor no recibió esa notificación
(ver [Solución de problemas](#solución-de-problemas)).

Si el cliente cancela en Khipu, vuelve a la factura, que sigue impaga, y puede intentarlo
de nuevo. Cada intento crea un pago nuevo en Khipu: si alguien llegara a pagar dos veces
la misma factura, WHMCS registra el segundo pago como crédito a favor del cliente.

---

## Monedas

Khipu cobra en las monedas que tenga habilitadas tu cuenta (por ejemplo CLP). Si facturas
en otra moneda, configura **Convertir a para procesar** con una moneda que tu cuenta
cobre: WHMCS convierte el saldo con su tipo de cambio antes de crear el pago.

El módulo recuerda en cada pago el monto original de la factura, así que:

- la factura queda pagada por su monto exacto, sin diferencias por tipo de cambio o redondeo;
- una reversa total devuelve exactamente lo que Khipu cobró, y una parcial, la
  proporción equivalente.

En pesos chilenos Khipu cobra sin decimales: una factura de $11.900,50 se cobra $11.901 y
queda pagada por completo.

---

## Reversas (billetera de reversas)

Desde la versión **3.0.0** puedes reversar un pago, es decir, devolverle el dinero al cliente,
desde la factura, con el circuito de abonos (*refunds*) de WHMCS. Los mensajes de Khipu
que verás en el registro de pasarela pueden llamarlas *devoluciones*: es lo mismo.

### Requisitos

- La **billetera de reversas** habilitada en tu cuenta Khipu. Si no la tienes,
  solicítala a **soporte@khipu.com**.
- **Saldo** en la billetera: el dinero que se reversa sale de ahí, no del pago original.
- Que el pago lo haya registrado la versión 3.0.0 o posterior del módulo (ver
  [Pagos registrados por la versión 2.x](#pagos-registrados-por-la-versión-2x)).
- Que no hayan pasado más de **180 días** desde la conciliación del pago.

### Saldo de la billetera

Los administradores con rol **Full Administrator** (o con el permiso **Ver los saldos de
Gateway**, *View Gateway Balances*) ven el saldo de la billetera de reversas en
**Facturación → Listado de transacciones** (*Billing → Transactions List*), en el bloque
**Saldos Gateway** (*Gateway Balances*). Si no aparece, la billetera no está habilitada o
Khipu no respondió a tiempo; recarga la página.

### Cómo reversar

1. Abre la factura pagada y entra a la pestaña **Abono** (*Refund*).
2. Elige la transacción de Khipu y el monto. Puede ser el total o una parte.
3. En **Tipo de abono** (*Refund Type*) elige **Reembolso a través de gateway (si lo admite
   el módulo)** (*Refund through Gateway*).
4. Confirma el abono.

WHMCS registra la reversa solo si Khipu la aceptó; la transacción de reversa queda con
el ID de la reversa en Khipu. Si algo falla, WHMCS muestra un aviso genérico y el
detalle queda en **Facturación → Registro de pasarela** (*Billing → Gateway Log*), en el
campo `resultado`, que dice siempre **si se devolvió dinero y si es seguro reintentar**.

### Qué esperar después

- **Toda reversa queda primero en proceso** y Khipu la concreta en su ciclo diario de
  reversas, aunque la billetera tenga saldo de sobra. Es el camino normal, no un
  error: WHMCS la registra y en el registro de pasarela queda el aviso de Khipu.
- **La billetera se descuenta al concretarse**, no al solicitar. Para que se concrete, debe
  tener un saldo mayor que las reversas del día. Si no se concreta dentro de 15 días
  hábiles, Khipu la da por **expirada** y no se realiza: mantén la billetera con saldo.
- Puedes hacer **varias reversas parciales** sobre un mismo pago hasta agotarlo.
- **Una reversa no se puede deshacer.**
- Los otros tipos de abono, **Sólo registro (devolución procesada manualmente)** (*Manual
  Refund Processed Externally*) y **Añadir al saldo del cliente** (*Add to Client's Credit
  Balance*), **no** mueven dinero en Khipu: solo lo anotan en WHMCS.

### Si el resultado es desconocido

Cada solicitud de reversa es una reversa nueva: dos solicitudes son dos devoluciones de dinero. Cuando
Khipu responde con un error de servidor o no responde, el módulo vuelve a consultar el
pago para averiguar qué pasó:

| `resultado` en el registro de pasarela | Qué significa | Qué hacer |
|---|---|---|
| «…confirma la reversa» | Khipu la registró; WHMCS también | Nada |
| «…todavía no aparece esta reversa, pero puede seguir procesándose» | Khipu aún no la muestra, pero una solicitud que no alcanzó a responder puede terminar de procesarse | Revisa el pago en el portal de Khipu en unos minutos y reintenta solo si no aparece |
| «RESULTADO DESCONOCIDO… es probable que SÍ se haya reversado» | El pago muestra cambios en sus reversas | **No reintentes.** Revisa el pago en el portal de Khipu y, si se reversó, regístralo en WHMCS con **Sólo registro (devolución procesada manualmente)** |
| «RESULTADO DESCONOCIDO: no se pudo confirmar…» | Khipu no respondió ni a la consulta | **No reintentes.** Revisa en el portal de Khipu o escribe a soporte@khipu.com con el `payment_id` |

---

## Actualizar desde la versión 2.x

La versión 3.0.0 usa la API v3 de Khipu y necesita un dato nuevo, la **API Key**.

1. **Respalda** la base de datos y la carpeta `modules/gateways/` de tu WHMCS.
2. Sube los archivos de la versión nueva igual que en la [instalación](#instalación).
   Reemplazan a los de la 2.x.
3. Abre la configuración de Khipu en **Configuración → Ajustes → Pasarelas de pago**,
   completa la **API Key** y guarda. El **ID Cobrador** y la **Llave secreta** se conservan.
4. Borra la carpeta `modules/gateways/vendor/khipu/`, que traía la 2.x. Si ningún otro
   módulo usa `modules/gateways/vendor/`, puedes borrar la carpeta `vendor/` completa.

Hasta que completes la API Key, el botón de Khipu muestra «El pago con Khipu no está
disponible en este momento».

### Pagos registrados por la versión 2.x

- **Pagos en curso al actualizar.** Un pago creado con la 2.x que el cliente paga después
  de actualizar se sigue confirmando: el módulo atiende las notificaciones del formato
  antiguo durante la transición.
- **Reversas.** La 2.x registraba cada pago con un identificador que no es el
  `payment_id`, así que esos pagos no se pueden reversar desde WHMCS: hazlo desde el
  portal de Khipu y regístralo en WHMCS con **Sólo registro (devolución procesada
  manualmente)**.

---

## Solución de problemas

Casi todo queda en **Facturación → Registro de pasarela** (*Billing → Gateway Log*),
filtrando por Khipu.

| Síntoma | Causa más probable | Qué hacer |
|---|---|---|
| El botón dice «El pago con Khipu no está disponible en este momento» | Falta la API Key, el ID Cobrador o la Llave secreta | Complétalos en la configuración de Khipu |
| «No pudimos iniciar el pago con Khipu» al presionar el botón | Khipu rechazó crear el pago | El registro de pasarela trae el motivo. Lo más común: API Key incorrecta, o una **URL del sistema WHMCS** que no es pública o no es `https` |
| El cliente pagó y la factura sigue impaga | La notificación no llegó o no se pudo validar | Revisa el registro de pasarela: «Firma inválida» = Llave secreta incorrecta; «otra cuenta de cobro» = ID Cobrador incorrecto. Si no hay ninguna entrada, Khipu no alcanza tu servidor: revisa HTTPS, firewall o reglas que bloqueen `modules/gateways/callback/khipu.php` |
| El registro dice «ATENCIÓN: antes de que WHMCS registrara este pago…» | Las notificaciones fallaban y el pago se anuló o se reversó (total o parcialmente) desde el portal de Khipu antes de que WHMCS lo registrara. El módulo registra el pago igual, porque Khipu sí lo recibió | Cuando la reversa se concrete, regístrala en la factura con **Sólo registro (devolución procesada manualmente)**. Si expira, no hay nada que registrar |
| Un pago no se registra y el registro dice «El pago no lo creó este módulo con la Llave secreta actual» | Cambiaste la **Llave secreta** mientras ese pago estaba en curso | Registra ese pago a mano en la factura. Los pagos nuevos se registran solos |
| «Este enlace de pago no es válido» | El formulario de pago se alteró, o cambió la Llave secreta después de abrir la factura | Recarga la factura y vuelve a presionar el botón |
| No aparece el saldo de la billetera | Billetera no habilitada, rol sin permiso, o Khipu no respondió | Revisa el permiso **Ver los saldos de Gateway**; para habilitar la billetera, soporte@khipu.com |
| «La billetera de reversas no está habilitada en tu cuenta Khipu» | Tu cuenta no tiene el permiso | Solicítalo a **soporte@khipu.com** |
| «…no es reembolsable» | El pago ya se reversó por completo, se reversó por otro medio o pasaron más de 180 días | El `resultado` del registro de pasarela indica cuál de los casos es |
| «Khipu no encuentra este pago» al reversar | El pago lo registró la versión 2.x | Revérsalo desde el portal de Khipu |
| «Khipu rechazó la API Key configurada» al reversar | API Key incorrecta o revocada | Corrígela en la configuración de Khipu |

---

## Soporte

- Correo: **soporte@khipu.com**
- Código fuente e historial de versiones: <https://github.com/khipu/whmcs-khipu>

Al escribir, indica la versión del módulo y de WHMCS, el número de factura y, si es una
reversa, el `payment_id` que aparece en el registro de pasarela. **Nunca envíes tu Llave secreta
ni tu API Key.**
