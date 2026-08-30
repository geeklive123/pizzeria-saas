# Bloque 2 — Estado de impresora para roles operativos

## Objetivo
Mostrar a cashier/waiter/kitchen si el agente está `En línea` o `Fuera de línea`, sin permitirles editar configuración.

## Requisitos
- Indicador pequeño y global en cabecera/sidebar.
- Reutilizar exactamente el criterio actual de Configuración para determinar estado del PrintAgent.
- owner/admin conservan permisos actuales.
- cashier/waiter/kitchen solo visualizan estado.
- No exponer token ni datos sensibles.
- No hacer polling agresivo.

## No tocar
Protocolo del agente, `windows_raw`, impresión de cocina/ticket ni permisos administrativos.

## Pruebas
Visibilidad para roles operativos y prohibición de edición.
