# Changelog - obsstocks

## 2.0.1 - 2026-11-08
- Botón «Productos bajo mínimos» en el bloque de descargas y botón «Descargar este listado (CSV)» en el panel de
  productos bajo mínimos: el mismo CSV que llega por email (REFERENCE, NAME, CURRENT STOCK, MIN STOCK, NAME EN, QTY)
  sin esperar al cron.
- El texto del email de bajo mínimos menciona también la columna del nombre en inglés.

## 2.0.0
- Reescrito para PrestaShop 1.7 a 9 sobre la 1.5 de OBSolutions: configuración en el propio módulo, importador CSV
  con columnas en cualquier orden, descargas y emails de stock. Mantiene las columnas de la exportación de bajo
  mínimos de la 1.5 (con NAME EN y QTY).

## 1.5
- Versión original de OBSolutions.
