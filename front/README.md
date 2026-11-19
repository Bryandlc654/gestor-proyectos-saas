# Vértice · Frontend React

Frontend nuevo de Vértice. Convive con la interfaz clásica en PHP durante la migración.

## Stack

- React 19 + TypeScript
- Vite
- React Router
- TanStack Query
- Tailwind CSS
- Lucide React

## Comandos

```bash
npm install
npm run dev      # http://localhost:5174/app
npm run build    # genera front/dist
npm run typecheck
```

El servidor de desarrollo hace proxy de `/api`, `/uploads`, `/login` y `/logout` a `http://127.0.0.1:8899`.

## Arquitectura

```
src/
  app/                 # providers, rutas y error boundary
  components/          # shell y componentes UI reutilizables
  features/
    auth/              # bootstrap de sesión/permisos
    dashboard/
    projects/
    tasks/
    calendar/
    crm/
    cash/
    billing/
    finance/
    profitability/
    reports/
    settings/
    users/
    quotes/
    templates/
    trash/
    support/
    connections/
    apiTokens/
  lib/                 # cliente API y formato
  types.ts             # contratos con la API PHP
```

Reglas:

- Las páginas no llaman a `fetch` directamente: usan `lib/api.ts`.
- Los datos de servidor se gestionan con TanStack Query y claves por recurso.
- Las mutaciones invalidan las queries afectadas (dashboard, proyecto, tareas).
- El bootstrap entrega usuario, agencia, módulos, navegación y CSRF.
- La sesión es la misma cookie PHP; las escrituras envían `X-CSRF-Token`.

## API

Endpoints en `api/app/`:

- `GET bootstrap.php`: sesión, permisos, navegación y proyectos del sidebar.
- `GET dashboard.php`: métricas, proyectos y tareas del usuario.
- `GET projects.php` y `GET projects.php?id=...`: listado y detalle.
- `GET|POST tasks.php`: listado y cambio de estado.
- `GET calendar.php`: eventos del mes (tareas, proyectos y cobros).
- `GET crm.php`: pipeline, empresas, contactos y actividades.
- `GET cash.php`: resumen, cuentas y movimientos de caja.
- `GET|POST billing.php`: facturas y cambio de estado con sincronización de caja.
- `GET|POST finance.php`: cobros, pagos y registro de pagos.
- `GET profitability.php`: métricas por proyecto.
- `GET reports.php`: datasets de reportes y enlace de exportación.
- `GET|POST settings.php`: perfil, contraseña, actividad, permisos, avatares y 2FA.
- `POST /api/users.php`: listado y cambio de rol/eliminación de usuarios (API existente).
- `GET|POST quotes.php`: listado, detalle, estado y borrado de cotizaciones.
- `POST quote-save.php`: creación y edición de cotizaciones.
- `GET|POST templates.php`: plantillas y creación de proyectos desde plantilla.
- `GET|POST trash.php`: restauración y borrado definitivo de proyectos archivados.
- `GET|POST support.php`: solicitudes de soporte, resolver y reabrir.
- `GET|POST connections.php`: código, solicitudes y agencias conectadas.
- `GET|POST api-tokens.php`: tokens, permisos y revocación.
- `GET project-tabs.php`: comentarios, archivos, enlaces, tickets y actividad.
- `GET project-insights.php`: finanzas y datos de Gantt del proyecto.

Todos exigen sesión. Los `POST` exigen CSRF.

## Estado de migración

Migrados a React:

- Dashboard
- Proyectos (listado y detalle)
- Tareas (listado y cambio de estado)
- Calendario
- CRM (pipeline, empresas, contactos y actividades)
- Caja (resumen y movimientos)
- Facturación (facturas y cambio de estado)
- Cobros y pagos (cuentas y registro de pagos)
- Rentabilidad
- Reportes (proyectos, personas, clientes y vencimientos)
- Configuración (perfil, seguridad, actividad y permisos)
- Configuración ampliada: avatares, 2FA, webhooks y columnas Kanban
- Usuarios (listado, cambio de rol y eliminación)
- Cotizaciones (listado, detalle, formulario, estado y PDF)
- Plantillas (listado, creación de proyecto y borrado)
- Papelera (restaurar y eliminar definitivamente)
- Soporte (crear, resolver y reabrir solicitudes)
- Agencias conectadas (código, solicitudes y desconexión)
- API tokens (crear, listar y revocar)
- Proyecto: comentarios, archivos/enlaces, tickets, actividad, Gantt, finanzas y subida de archivos
- Webhooks, avatares, 2FA, columnas Kanban y marca de agencia
- Proyecto: carpetas y creación de enlaces

Siguen en PHP, accesibles desde el menú React:

- Pantallas migradas: todo el front (aplicación, autenticación, registro, invitaciones, landing, marca y legales)
- PHP queda únicamente como backend/API, callbacks OAuth y descargas

La estrategia es strangler: cada módulo se porta cuando su API y su pantalla React están completos. Hasta entonces se conserva la ruta PHP para no romper funcionalidad.
