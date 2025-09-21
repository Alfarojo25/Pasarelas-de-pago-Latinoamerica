# Plugin Monedas para WooCommerce

Una extensión profesional y autocontenida para gestionar múltiples monedas en WooCommerce utilizando únicamente tasas definidas manualmente (sin llamadas a APIs externas). Diseñada para entornos que requieren control total sobre las tasas de cambio, cumplimiento fiscal flexible y extensibilidad mediante hooks y filtros bien delineados.

---
## Tabla de Contenido
1. Visión General
2. Características Principales
3. Instalación
4. Actualización y Migraciones
5. Configuración Detallada
6. Redondeo y Formato Numérico
7. Precios Específicos por Moneda
8. Cupones Multi‑Moneda
9. Modo Visual vs. Multi‑Moneda Real
10. Geolocalización y País Fiscal
11. Impuestos y Exenciones
12. Pasarelas de Pago y Restricciones
13. Reportes Multi‑Moneda
14. Cache y Rendimiento
15. Seguridad y Hardening
16. Hooks (Acciones & Filtros)
17. Tests y Calidad
18. Internacionalización (i18n)
19. Suscripciones Internas
20. Endpoints REST Suscripciones
21. Autopago (Tokenización & Retry)
22. Limitaciones Conocidas
23. Licencia
24. Changelog

---
## 1. Visión General
El plugin proporciona un sistema de conversión y/o almacenamiento de precios en múltiples monedas basado en una moneda base de WooCommerce. Permite elegir entre una capa meramente visual o un modo multi‑moneda real que reescribe precios en el carrito y guarda pedidos en la moneda seleccionada.

## 2. Características Principales
Core:
- Tasas manuales (CODIGO|Símbolo|Tasa) respecto a la moneda base.
- Conversión de productos simples y variables.
- Modo multi‑moneda real con persistencia en pedidos.
- Shortcode `[plugin_monedas_selector]`, widget y bloque Gutenberg.
- Cookie + nonce de seguridad para selección de moneda.

Formato y redondeo:
- Redondeo decimal base (none, round, floor, ceil).
- Redondeo “cash” por múltiplos (p.ej. 0.05, 50, 1000).
- Redondeo por magnitud (miles/centenas/… /0.1) con modos nearest, up, down.
- Decimales configurables por moneda.
- Formato local de separadores (miles y decimal) por moneda.

Precios avanzados:
- Overrides por moneda a nivel de producto y variación (regular / oferta) sin tocar la moneda base.

Cupones:
- Conversión dinámica de cupones de importe fijo (cart/product) manteniendo meta base.

Impuestos y fiscalidad:
- Forzado de país fiscal según moneda (opcional).
- Exenciones por moneda, país o rol (impuesto 0).

Geolocalización:
- Mapa País→Moneda editable + detección IP.

Pasarelas:
- Mensaje cuando la moneda mostrada difiere de la moneda de cobro (modo visual).
- Restricción opcional de pasarelas por moneda (`gateway_id|MON1,MON2`).

Reportes:
- Página administrativa: totales por moneda y equivalentes en base con cache.
- Métricas de Autopago: activas, éxitos, fallos, abandonos.
- Discriminación por método de pago (total y conteo por pasarela dentro de cada moneda).
- Export CSV del reporte con columnas extendidas (moneda, pedidos, total_moneda, total_base, métodos_json, autopay_stats).

Rendimiento:
- Cache de tasas (transient), cache en memoria de precios override, cache de reporte.

Internacionalización:
- Archivo `.pot` listo para traducciones.

## 3. Instalación
1. Descargar el paquete ZIP (ver sección “Distribución”).
2. En el panel de WordPress: Plugins > Añadir nuevo > Subir.
3. Activar el plugin.
4. Asegurarse de tener WooCommerce activo y configurada la moneda base.

## 4. Actualización y Migraciones
Cambios menores (nuevas tasas, ajustes redondeo) no requieren migraciones. Para nuevas versiones mayores:
- Revisar el CHANGELOG (si se añade) antes de actualizar en producción.
- Hacer copia de seguridad de base de datos (especialmente si se usan overrides extensivos).

## 5. Configuración Detallada
Ruta: WooCommerce > Ajustes > pestaña “Monedas Local”.

Secciones visuales (tablas) para: Tasas, Redondeo efectivo, Redondeo por magnitud, Geolocalización, Decimales, Formato, Comportamiento, Exenciones, País fiscal según moneda, Pasarelas.

Formato tasas (una por línea):
```
USD|$|1.00
EUR|€|0.90
MXN|$|17.50
```
La tasa siempre es respecto a la moneda base configurada en WooCommerce.

## 6. Redondeo y Formato Numérico
Orden interno de operaciones:
1. Precio base (o override) × tasa.
2. Redondeo decimal (modo base).
3. Redondeo “cash” (si aplica).
4. Redondeo de magnitud (si aplica).
5. Formateo con separadores personalizados.

## 7. Precios Específicos por Moneda
En cada producto (y variación) aparece el metabox “Precios por Moneda (Plugin Monedas)”. Si se define un valor para una moneda, sustituye la conversión automática (regular/sale). Si falta sale override, cae al regular override como fallback.

## 8. Cupones Multi‑Moneda
Cupones tipo `fixed_cart` y `fixed_product` almacenan importe base en meta. En ejecución se convierten a la moneda activa mediante la tasa y redondeos configurados. Cupones porcentuales no requieren adaptación.

## 9. Modo Visual vs. Multi‑Moneda Real
Modo visual: solo reescribe la presentación de precios; gateways siguen operando en la moneda base.
Modo real: recalcula precios en el carrito y asigna la moneda seleccionada al pedido — guardando tasa y moneda base para referencia.

## 10. Geolocalización y País Fiscal
El mapa País→Moneda permite asignar una moneda inicial si no existe cookie previa. Opcionalmente puede forzarse el país fiscal para reglas de impuestos coherentes con la moneda mostrada.

## 11. Impuestos y Exenciones
Listas separadas de monedas, países y roles que fuerzan exención (impuesto 0). Se aplican en fase temprana del cálculo para evitar fugas de redondeo.

## 12. Pasarelas de Pago y Restricciones
El mapa `gateway_id|MON1,MON2` filtra pasarelas no admitidas para la moneda activa. En modo visual se muestra un aviso si la moneda cobrada es la base y difiere de la mostrada.

## 13. Reportes Multi‑Moneda
Herramientas > Reporte Multi‑Moneda: totales y conteos por moneda + equivalentes convertidos a la base usando la tasa guardada en cada pedido (con cache de 10 minutos para reducir carga).

Extensiones avanzadas del reporte:
- Desglose por método de pago: cada moneda agrupa métodos (gateway_id) con montos y conteos.
- Métricas Autopay agregadas (suscripciones con autopago activo, éxitos, fallos, abandonos).
- Export CSV: incluye columnas base y campos JSON codificados para métodos y métricas, ideal para análisis externo / BI.

## 14. Cache y Rendimiento
- Tasas: transient 1h (se invalida al guardar).
- Reporte: transient de 10 minutos por rango.
- Overrides de producto: cache estática por petición.

## 15. Seguridad y Hardening
- Sanitización estricta de input (monedas: A–Z, longitud máxima controlada).
- Nonce obligatorio para cambio de moneda vía GET.
- Límites de tamaño y líneas en mapas (pasarelas, geolocalización, etc.).
- Solo usuarios con `manage_woocommerce` pueden modificar ajustes.
- Filtros públicos permiten auditoría (p.ej. validar tasas antes de uso real).
- No se hacen llamadas externas (reducción superficie de ataque).

## 16. Hooks (Acciones & Filtros)
Acciones:
- `plugin_monedas_after_rates_loaded( array $rates )` – tras parsear y filtrar tasas.

Filtros:
- `plugin_monedas_rates( array $rates )` – modificar o añadir tasas.
- `plugin_monedas_available_currencies( array $list )` – agregar o alterar metadatos de monedas.
- `plugin_monedas_converted_price( float $converted, float $original, WC_Product $product, float $rate, string $selected )` – precio convertido de producto simple.
- `plugin_monedas_converted_variation_price( float $converted, float $original, int $variation_id, WC_Product_Variation $variation, float $rate, string $selected )` – precio variación.
- `plugin_monedas_coupon_amount( float $converted, float $base_amount, WC_Coupon $coupon, string $selected, string $base_currency )` – ajuste final de cupones.

## 17. Tests y Calidad
Suite inicial en `tests/`:
- `test-currency-basic.php` – conversión base y tasa 1.
- `test-rounding-overrides.php` – redondeo cash y magnitud.
- `test-coupon.php` – conversión cupón importe fijo.
Extiende añadiendo pruebas de exenciones y formatos según tus necesidades CI.

## 18. Internacionalización (i18n)
Archivo base `languages/plugin-monedas.pot`. Use herramientas como Poedit para generar `.po/.mo`. Mantener sincronizado tras cambios en cadenas.

## 19. Suscripciones Internas
El plugin incluye un sistema de suscripciones propio, independiente de "WooCommerce Subscriptions". No requiere extensiones de pago y crea un ciclo básico de renovaciones manuales o semi‑automáticas.

Flujo básico:
1. El usuario marca el checkbox de opt‑in en el checkout.
2. Al completarse el pedido se genera una Suscripción interna (CPT `pm_subscription`).
3. Cada suscripción almacena: moneda base (soporte multi‑base: distintas suscripciones pueden haber nacido de diferentes monedas base históricas), moneda suscripción, tasa inicial, política de tasa, pasarela, historial de tasas/eventos, intervalo en días y próxima fecha de renovación.
4. Un cron diario genera pedidos de renovación cuando corresponde.
5. El usuario puede cancelar con un botón (nonce) en Mi Cuenta.

Políticas de tasa soportadas:
- `fixed_initial`: congela la tasa guardada y la reutiliza siempre.
- `update_renewal`: recalcula la tasa vigente (filtro aplicable) al crear cada pedido de renovación y la actualiza.
- `flag_manual`: detecta nuevas tasas y las registra como "pending" sin aplicarlas, quedando a la espera de confirmación externa (puedes desarrollar tu UI usando el meta `_plugin_monedas_subscription_rate_pending`).

Metadatos (post meta en `pm_subscription`):
- `_plugin_monedas_subscription_base_currency`
- `_plugin_monedas_subscription_currency`
- `_plugin_monedas_subscription_rate_initial`
- `_plugin_monedas_subscription_rate_policy`
- `_plugin_monedas_subscription_rate_history` (JSON)
- `_plugin_monedas_subscription_gateway`
- `_plugin_monedas_subscription_rate_pending`
- `_plugin_monedas_subscription_next_renewal` (timestamp UTC)
- `_plugin_monedas_subscription_interval_days`
- `_plugin_monedas_subscription_initial_order`
- Metas Autopay (ver sección 21)

Historial:
Cada transición (creación, renovación, auto_update, flag, retry, cancel, gateway_revert, charge_success, charge_fail, charge_abandon, abandon_notify_scheduled, abandon_notify_sent) agrega una entrada con timestamp y datos auxiliares.

Renovaciones:
- Se crea un nuevo pedido WooCommerce (estado `pending`) copiando ítems del pedido inicial y recalculando precios según política.
- Si la política produce cambio de tasa se registra evento `auto_update` o `flag`.

Cancelación:
- Botón con nonce en Mi Cuenta. Cambia el estado interno a cancelado (post_status `pm_cancelled`).

Cron y limpieza:
- Evento diario: genera renovaciones y limpia suscripciones obsoletas según días de retención.

Filtros clave:
- `plugin_monedas_live_rate_for_subscription( float $rate, string $base_currency, string $subscription_currency )` – Ajustar tasa viva usada para políticas dinámicas.
- `plugin_monedas_subscription_allowed_gateway( bool $allowed, string $gateway_id, string $currency, string $country, int $subscription_id )` – Validar si una pasarela sigue siendo válida para la suscripción.
- `plugin_monedas_geoip_country( string $country )` – Resolver país por GeoIP si no se dispone de billing_country.

Personalización recomendada:
- Añade UI para aprobar tasas pendientes (`flag_manual`).
- Intercepta la creación de pedidos de renovación para aplicar descuentos de fidelización.

Limitaciones del sistema interno:
- Prorrateos complejos de upgrades/downgrades no se recalculan automáticamente.
- No incluye pausa/suspensión temporal; deberás implementar un meta propio si lo necesitas.

## 20. Endpoints REST Suscripciones

Base namespace: `plugin-monedas/v1`

Autenticación: Necesita usuario autenticado (cookie/nonce estándar de WordPress). Todas las rutas devuelven JSON.

### Crear suscripción
POST `/wp-json/plugin-monedas/v1/subscriptions`

Parámetros (body JSON o form-data):
- `order_id` (int, requerido) – ID del pedido existente base.
- `interval_days` (int, opcional) – Debe pertenecer a la lista configurada en ajustes (p.ej. 7,30,90). Si se omite se usa el intervalo por defecto.

Respuesta (200 ejemplo):
```
{
	"id": 123,
	"currency": "USD",
	"rate": 1.0,
	"interval": 30,
	"next_renewal": 1735689600,
	"policy": "fixed_initial",
	"initial_order": 987,
	"gateway": "cod",
	"links": { "self": "https://ejemplo.com/wp-json/plugin-monedas/v1/subscriptions/123" }
}
```

Errores comunes:
- 400 `invalid_order` – Pedido no existe.
- 400 `not_eligible` – Filtro `plugin_monedas_subscription_order_eligible` devolvió false.
- 403 `forbidden` – Pedido no pertenece al usuario.
- 409 `exists` – Ya hay una suscripción para ese pedido.

### Listar suscripciones del usuario
GET `/wp-json/plugin-monedas/v1/subscriptions`

Respuesta (array de objetos con la misma estructura que el POST de creación).

### Cancelar suscripción
DELETE `/wp-json/plugin-monedas/v1/subscriptions/{id}`

Respuesta (200):
```
{ "id": 123, "status": "cancelled" }
```

### Hook de acción tras creación
`do_action( 'plugin_monedas_subscription_created', int $subscription_id, WC_Order $order );`
Permite disparar lógica adicional (enviar email, registrar en CRM, programar tareas específicas, tokenizar con pasarela, etc.).

### Filtro de elegibilidad
`apply_filters( 'plugin_monedas_subscription_order_eligible', bool $eligible, WC_Order $order );`
Retorna true/false para habilitar la creación. Útil para requisitos como importe mínimo, categorías permitidas, rol de usuario, etc.

### Notas i18n
Todas las nuevas cadenas REST usan el dominio `plugin-monedas`. Tras cambios ejecute su herramienta de extracción para regenerar `plugin-monedas.pot`.

## 21. Autopago (Tokenización & Retry)

El plugin implementa un subsistema de “Autopago” opcional para intentar cobros automáticos de las renovaciones usando tokenización de pasarelas que ya existan en el sitio (p.ej. PayPal con Billing Agreements / Reference Transactions, Mercado Pago con customer + card token). No almacena directamente credenciales sensibles: sólo guarda identificadores retornados por la pasarela post‑autorización inicial.

Objetivos de diseño:
- Opt‑in explícito del usuario (toggle en Mi Cuenta y vía REST PATCH).
- Un solo reintento programado (24h configurable) para minimizar riesgo de cargos repetidos.
- Extensible mediante filtros para soportar más proveedores.
- No bloquea el flujo si la pasarela no soporta tokenización: simplemente no activa autopago.

Metas utilizadas (por suscripción):
- `_plugin_monedas_subscription_autopay_enabled` – bandera boolean.
- `_plugin_monedas_subscription_autopay_provider` – identificador (`paypal`, `mercadopago`, etc.).
- `_plugin_monedas_subscription_autopay_token` – ID acuerdo / card_id referencial.
- `_plugin_monedas_subscription_autopay_customer` – payer id / customer id remoto.
- `_plugin_monedas_subscription_autopay_attempts` – contador de intentos.
- `_plugin_monedas_subscription_autopay_max` – máximo permitido (por defecto 2: intento inicial + 1 retry).
- `_plugin_monedas_subscription_autopay_last` – JSON con último resultado.
- `_plugin_monedas_subscription_autopay_next_retry` – timestamp del próximo reintento.
- `_plugin_monedas_subscription_autopay_abandon_notify_scheduled` – timestamp programado para email diferido.

Flujo de una renovación con autopago:
1. Cron detecta necesidad de renovar y crea pedido `pending`.
2. Hook `plugin_monedas_subscription_renewal_order_created` dispara clase Autopay.
3. Filtro `plugin_monedas_autopay_process_charge` delega a implementación por pasarela.
4. Si éxito: pedido `processing`/`completed`, evento `charge_success` y limpieza de retries.
5. Si fallo: evento `charge_fail`, se programa retry si quedan intentos (`retry_scheduled`).
6. Si se agotan intentos: evento `charge_abandon` y se programa (opcional) notificación diferida.

Notificación diferida de abandono:
- Ajustes: “Email abandono habilitado” + “Delay email abandono (horas)”. 
- Al abandonarse se programa evento `abandon_notify_scheduled`; al ejecutarse envía email `abandon_delayed` (recordatorio manual) y registra `abandon_notify_sent`.

UI Historial eventos Autopago:
- En Mi Cuenta, detalle de la suscripción: tabla cronológica con intentos (éxitos, fallos, abandonos, reintentos programados, recordatorios). Limita el tamaño según ajuste de historial.

Retry:
- Controlado por filtro `plugin_monedas_autopay_retry_delay` (por defecto 24h). Máximo intentos definido por meta `_autopay_max`.

Emails:
- Estados cubiertos: `success`, `fail` (con retry), `abandon`, `abandon_delayed`.
- Personalización vía filtros `plugin_monedas_autopay_email_*`.

Hooks/Filtros Autopay (además de los previos):
- `plugin_monedas_autopay_email_sent` – tras enviar email.

Buenas prácticas:
- Validar monto y moneda antes de mandar a la pasarela.
- Registrar logs (sin datos sensibles) para auditoría.
- Avisar claramente al usuario al activar autopago.

Limitaciones Autopay actuales:
- La lógica de cobro por pasarela depende de implementación externa vía filtro; este plugin no incluye SDKs.

## 22. Limitaciones Conocidas
- No convierte comisiones de pasarela automáticamente.
- Prorrateos complejos no automatizados (solo eventos).
- Renovaciones internas requieren que el usuario pague el nuevo pedido salvo éxito de Autopago.

## 23. Licencia
Este plugin se distribuye bajo los términos de la **GNU General Public License v3.0 o posterior (GPL-3.0-or-later)**.

Permite:
- Uso, copia y distribución.
- Modificación y creación de trabajos derivados.
- Distribución de versiones modificadas siempre que se mantenga la misma licencia.

Requiere:
- Mantener avisos de copyright y licencia.
- Código derivado también debe ser GPL-3.0 (o posterior compatible).

Para el texto completo consulte: https://www.gnu.org/licenses/gpl-3.0.html

---
## 24. Changelog

### 1.2.0 (en desarrollo)
Novedades REST:
- Endpoint POST creación suscripción (intervalos configurables).
- Endpoint GET listado de suscripciones del usuario.
- Endpoint DELETE cancelación.
- Respuesta JSON enriquecida (moneda, tasa, intervalo, próxima renovación, política, pedido inicial, pasarela, links).
- Hook `plugin_monedas_subscription_created`.
- Filtro `plugin_monedas_subscription_order_eligible`.
Autopago:
- Clase interna `Subscriptions_Autopay` con intentos y retry programado.
- Campo `autopay` en respuestas POST/GET.
- Endpoint PATCH `/subscriptions/{id}/autopay` para activar/desactivar.
- Filtro `plugin_monedas_autopay_process_charge` para integrar pasarelas (PayPal, Mercado Pago, etc.).
- Meta de control de token, provider, attempts, max.
- Retry configurable (delay) y máximo 1 reintento adicional.
- Emails de notificación (éxito, fallo con retry, abandono, abandono diferido) con filtros de contenido y destinatarios.
- Columnas admin para estado de autopago y último intento.
- Métricas agregadas de autopago en el reporte multi‑moneda.
- Nuevos filtros de correo y delay (`plugin_monedas_autopay_email_*`, `plugin_monedas_autopay_retry_delay`) y acción `plugin_monedas_autopay_email_sent`.
Suscripciones internas:
- Sistema independiente multi‑base (cada suscripción conserva su moneda base de origen).
- Políticas de tasa: fixed_initial, update_renewal, flag_manual.
- Aprobación pendiente para `flag_manual` mediante meta `_plugin_monedas_subscription_rate_pending`.
- Historial enriquecido con eventos de Autopago y notificaciones.
Reporte:
- Desglose por método de pago.
- Exportación CSV con columnas y datos agregados.
- Métricas de Autopago (activas/éxitos/fallos/abandonos) por rango.
UI / UX:
- Tabla historial de eventos Autopago en Mi Cuenta (suscripción).
- Programación y envío diferido de recordatorio de abandono (`abandon_delayed`).

### 1.1.0
- Tabla maestra unificada País → Moneda con símbolo y decimales automáticos.
- Redondeo combinado (cash + magnitud) simultáneo.
- Validación visual de filas (tasa > 0, país no duplicado).
- Switches individuales por pasarela con tooltip, indicador "Ninguna activa", contador (n/total) y botones activar/desactivar todas.
- Ejemplo de precio formateado usando separadores WooCommerce y decimales específicos.
- Internacionalización ampliada (en_US, es_ES, pt_BR, fr_FR) y compilación runtime de `.po` → `.mo`.
- Protección reforzada compilador runtime: lock 60s, límite 512KB, máx 2000 msgid, control concurrencia y tamaño final <1MB.
- Enlace GitHub del autor en la lista de plugins; añadido Author URI.
- Países dinámicos desde WooCommerce con fallback ampliado.
- Auto-migración de configuración previa a nueva estructura.

### 1.0.0
- Versión inicial: tasas manuales, selector, widget, bloque, overrides por moneda, redondeo cash y magnitud, exenciones, geolocalización, reporte multi‑moneda, restricciones de pasarelas y capa multi‑moneda real experimental.
