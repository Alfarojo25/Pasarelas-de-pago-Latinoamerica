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
19. Buenas Prácticas de Uso
20. Limitaciones Conocidas
21. Roadmap Sugerido
22. Licencia

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

## 19. Buenas Prácticas de Uso
- Documentar claramente al cliente si se opera en modo visual.
- Revisar tasas periódicamente (control interno / auditoría).
- Utilizar hooks para validar integridad (ej. impedir tasa < 0.0001).

## 20. Limitaciones Conocidas
- No gestiona suscripciones recurrentes multi‑moneda aún.
- No convierte comisiones de pasarela automáticamente.
- Reporte no discrimina por métodos de pago todavía.

## 21. Roadmap Sugerido
- Suscripciones multi‑moneda.
- Comisiones y fees convertidos dinámicamente.
- Panel analítico por producto y moneda.
- CLI para sincronizar tasas internas.

## 22. Licencia
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
Contribuciones, issues y mejoras son bienvenidas. Procure mantener la coherencia de seguridad y la limpieza del namespace.
