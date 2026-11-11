# Changelog - obsstocks

## 2.1.0 - 2026-11-11
- Pestaña «Avisos»: buscador instantáneo por referencia o nombre sobre todos los productos y combinaciones, con el
  stock mínimo y la cantidad a pedir editables en la misma fila (se guardan solos). Filtros «Con aviso», «Bajo
  mínimo» y «Todos los productos», botón para quitar un aviso y aviso de las referencias que ya no están en ningún
  producto. No cambia el stock real. Cada cambio a mano queda en el registro.
- Importar CSV: nuevo modo «sustituir» para los avisos. Los avisos de las referencias que no vienen en el archivo se
  borran, de modo que quedan exactamente como el archivo. «Comprobar archivo» dice antes cuántos y cuáles se borran,
  y antes de borrar se guarda una copia de todos los avisos (pestaña Registro, se conservan 15). El modo por defecto
  sigue siendo el de siempre: lo que no viene en el archivo no se toca.
- La configuración pasa a pestañas: Resumen, Avisos, Importar CSV, Emails y cron, Registro.
- Conectado al servidor de actualizaciones de josra.
- Un 0 en mínimo sigue significando «sin aviso» y se guarda como hasta ahora.

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
