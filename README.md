# Vertice - Gestor de Proyectos, Tareas, Tickets y Recursos

Un sistema de gestión ágil, moderno e intuitivo desarrollado en **PHP 8**, **MySQL** y estilizado con **Tailwind CSS**, diseñado con un enfoque prioritario en la experiencia de usuario (**UI/UX**) y simplicidad de uso.

---

## 🚀 Características Principales

### 0. Multi-agencia (SaaS)
- **Cada agencia es un espacio aislado**: sus usuarios, proyectos, tareas, facturas y marca (nombre y logo) solo los ve esa agencia. El filtro se aplica en el servidor a partir de la agencia guardada en la base de datos, no de la sesión.
- **Registro = nueva agencia**: quien se registra crea su agencia y queda como administrador. Ya no se puede elegir rol al registrarse.
- **Invitaciones por enlace**: el administrador invita a su equipo o a sus clientes desde *Usuarios → Invitar*, eligiendo el rol. El enlace sirve una sola vez, caduca a los 7 días y se puede revocar; en la base de datos solo se guarda su hash.
- Los roles (administrador, project manager, colaborador, cliente) funcionan igual que antes, pero siempre dentro de la propia agencia.
- **Agencias conectadas**: cada agencia tiene un código (p. ej. `ABCD-2345`) que su administrador puede compartir y regenerar. Otra agencia lo introduce en *Agencias conectadas*, y la primera acepta o rechaza la solicitud; cualquiera de las dos puede desconectarse después. Conectarse no comparte ningún contenido: solo habilita que más adelante se compartan proyectos. En los datos demo los códigos son `AGDM-2345` (Agencia Demo) y `NRTE-6789` (Estudio Norte).
- **Proyectos compartidos**: la agencia dueña comparte un proyecto con una agencia conectada y elige **Ver** (avance, tareas, comentarios, checklists, tickets y adjuntos en solo lectura) o **Colaborar** (además crear/mover tareas, comentar, gestionar checklists, crear y responder tickets y subir archivos). La agencia invitada elige qué miembros de su equipo participan desde *Equipo*. El proyecto aparece en su dashboard con la etiqueta *Compartido por X* (no se muestra a los usuarios con rol cliente). El presupuesto y las facturas nunca se comparten, y al desconectar las agencias sus proyectos dejan de estar compartidos. Los usuarios con rol **cliente** de la agencia invitada siempre tienen acceso limitado de solo lectura.
- **Enlace público por agencia**: cada agencia tiene un slug editable en *Configuración → Marca* (ej. `agencia-demo`). Al abrir `/<slug>` se muestra un login con el nombre y logo de esa agencia y solo permite entrar a sus usuarios.
- **Historial y auditoría**: se registran accesos a finanzas, cambios de acceso a proyectos compartidos, movimientos de tareas y acciones sobre facturas/proyectos. El admin tiene una página *Actividad* y cada proyecto muestra su historial.
- **Notificaciones**: campana interna para invitaciones, proyectos compartidos y tareas asignadas, con envío por correo si `MAIL_ENABLED=true` (`storage/mail.log` en desarrollo). Las acciones clave también avisan por correo: proyecto creado, factura emitida o pagada, oportunidad CRM ganada/perdida, nuevo miembro que acepta una invitación, tarea asignada, menciones y proyectos compartidos.
- **CRM**: pipeline de oportunidades por etapas (nuevo, contactado, propuesta, negociación, ganado, perdido), empresas, contactos y registro de actividades (llamadas, correos, reuniones, notas y tareas). Cada oportunidad guarda su valor estimado, puede generar una cotización con el cliente y el importe precargados, y las oportunidades ganadas se convierten en proyectos con un clic. Incluye historial comercial por empresa (oportunidades, propuestas, proyectos y actividades) y seguimiento de propuestas. Disponible para administradores y project managers, siempre aislado por agencia.
- **Soporte**: los administradores y project managers envían solicitudes al equipo de Vértice desde */support*; quedan guardadas con prioridad, categoría, estado e imagen adjunta opcional (máx. 5 MB), y se avisa por correo al buzón `SUPPORT_EMAIL` (con confirmación al usuario).
- **Papelera**: los proyectos se archivan en lugar de borrarse; se pueden restaurar o eliminar definitivamente desde *Papelera*.
- **Recuperación de contraseña y 2FA**: enlace de un solo uso por correo y verificación TOTP con códigos de recuperación desde *Seguridad*.
- **Kanban manual**: arrastrar y soltar tareas entre columnas y reordenarlas; el orden se guarda por proyecto.
- **Subtareas y dependencias**: las tareas pueden tener subtareas reales (responsable, fecha, prioridad y estado propios, visibles en el Kanban) y dependencias «bloqueada por», con detección de ciclos. Una tarea bloqueada no se puede completar hasta terminar sus dependencias.
- **Calendario unificado**: vista mensual y agenda con tareas por vencimiento, fechas límite de proyectos y cobros pendientes (facturas), con filtros por tipo, proyecto, tareas propias y completadas. Cada evento enlaza a su proyecto o a *Cobros y pagos*.
- **Cronograma (Gantt)**: fechas de inicio y vencimiento, hitos, dependencias con flechas, camino crítico con holguras y líneas base comparativas. Las barras se pueden arrastrar o redimensionar para reprogramar tareas.
- **Reportes y dashboards configurables**: informes por proyecto, persona y cliente con facturación, cobros, costos y margen; reporte de vencimientos; filtros por periodo y proyecto; gráficos; vistas guardadas; exportación CSV/PDF y envíos programados por correo con enlace temporal.
- **Estados personalizables**: el administrador puede crear, renombrar y reordenar las columnas del Kanban en *Configuración → Tareas*, indicando si cada una cuenta como pendiente, en curso o completada. Se aplica al tablero, a la lista, a los selectores de estado y al dashboard, y solo afecta a su agencia.
- **Menciones**: escribe `@correo` o usa el selector *Mencionar* en comentarios; el usuario mencionado recibe notificación.
- **Cuentas por cobrar y por pagar**: página *Cobros y pagos* con el saldo pendiente y vencido de las facturas (por cobrar) y de los gastos de proveedores (por pagar), filtros por estado, vencimiento y proyecto, y creación manual de cuentas por cobrar (asociadas o no a un proyecto) y por pagar (con proyecto opcional y pagos totales o parciales con historial). La pestaña *Finanzas* del proyecto enlaza directamente a sus cuentas por cobrar. Proveedores y clientes se pueden elegir de una lista de colaboradores, contactos del CRM y registros previos, o escribir un nombre nuevo. El dashboard enlaza los totales de cobro y pago con el módulo.
- **Cotizaciones con PDF**: módulo *Cotizaciones* para crear propuestas con ítems, cantidades, precios, descuentos e impuestos, cliente y proyecto opcional. Cada cotización se numera automáticamente (`COT-AAAA-NNN`), tiene estados (borrador, enviada, aceptada, rechazada) y se descarga en un PDF maquetado con el logo de la agencia. Se pueden crear desde una oportunidad del CRM, quedando vinculadas para su seguimiento.
- **Rentabilidad por proyecto**: vista consolidada con presupuesto inicial, ingresos asociados, gastos directos, pagos a colaboradores, dinero cobrado, dinero pendiente, margen y margen sobre ingresos. En *Cobros y pagos* cada cuenta por pagar se clasifica como gasto directo o pago a colaborador, y la pestaña *Finanzas* de cada proyecto muestra el desglose, los costos y el margen en tiempo real.
- **Exportaciones**: tareas, facturas y auditoría en CSV/PDF desde sus listados.
- **Plantillas y duplicado**: guarda un proyecto como plantilla (tareas y checklists) o duplica un proyecto completo.
- **Recordatorios**: tareas y facturas vencidas generan notificación/email mediante `php database/reminders.php` (ideal por cron diario).
- **Sesiones**: cambiar la contraseña o desactivar 2FA invalida las sesiones abiertas.
- **Mi perfil**: cada usuario puede cambiar su nombre, correo, foto y contraseña desde *Mi perfil*.

### 1. Autenticación y Seguridad
- **Inicio de sesión y registro de agencias** con validación y contraseñas cifradas mediante `password_hash()` con algoritmo **BCrypt**.
- **Inicio de sesión con Google** mediante OAuth 2.0 / OpenID Connect. Solo vincula cuentas existentes con correo verificado, valida el token con Google, respeta el doble factor y mantiene el aislamiento por agencia.
- Protección **CSRF** en todos los formularios y acciones del sistema.
- **Prevención de envíos duplicados** en formularios nativos: el botón queda bloqueado y se cancela el segundo envío, sin interferir con formularios AJAX ni confirmaciones.
- Consultas parametrizadas con **PDO Prepared Statements** para prevenir inyección SQL.
- Sanitización de salidas con `htmlspecialchars` contra ataques XSS.
- Protección en la carpeta `uploads/` mediante directivas `.htaccess` para bloquear ejecución de scripts maliciosos.

### 2. Dashboard Inteligente
- **Métricas visuales a primera vista**: Proyectos totales, tareas pendientes y en progreso, tickets activos y porcentaje de avance.
- Listado de proyectos con barra de progreso dinámica y contadores de recursos.
- Panel de tareas urgentes con detección de vencimientos cercanos.

### 3. Vista de Proyecto Modular (Pestañas Integradas)
Dentro de cada proyecto podrás gestionar:
1. 📋 **Actividades / Tareas**:
   - **Doble Vista: Tablero Kanban y Vista Lista**: Conmuta entre columnas dinámicas (*Por Hacer, En Progreso, Completada*) o formato lista compacto.
   - **Asignación a Colaboradores**: Selector desplegable con la lista real de miembros del equipo.
   - **Detalle y Comentarios por Tarea**: Al hacer clic en cualquier tarea se abre su modal de detalle, donde puedes ver y publicar notas o pegar capturas (`Ctrl+V`) asociadas exclusivamente a esa tarea.
2. ✅ **Checklists**: Listas de verificación interactivas con cálculo de avance porcentual y actualización instantánea vía API sin recargar la página.
3. 🎫 **Tickets de Soporte / Incidentes**: Gestión de fallas técnicas o solicitudes con niveles de severidad (*Baja, Media, Alta, Crítica*) y estados (*Abierto, En Revisión, Resuelto, Cerrado*).
4. 💬 **Muro de Notas y Comentarios**: Feed colaborativo general y visualización de comentarios originados en tareas (con chip de acceso directo a la tarea), con soporte para adjuntar y **pegar capturas con `Ctrl + V`**.
5. 📎 **Adjuntos (Archivos y Enlaces con Carpetas)**:
   - **Gestor de Carpetas**: Creación de carpetas con color personalizado y filtro instantáneo en vivo.
   - **Archivos Descargables**: Subida y descarga segura de documentos clasificados por carpetas.
   - **Enlaces Web**: Directorio organizado de URLs externas (Figma, GitHub, Notion, Drive) por carpetas.

---

## 🛠️ Requisitos de Ejecución
- **PHP 8.1 o superior** con extensiones `pdo`, `pdo_mysql` y `fileinfo` habilitadas.
- **MySQL 5.7+** o **MariaDB 10.3+**.
- Servidor local como **XAMPP**, **Laragon**, **WampServer** o el servidor embebido de PHP.

---

## 📦 Puesta en Marcha

### Paso 1: Configurar Credenciales (.env)
Copia `.env.example` como `.env` y ajusta los valores de conexión. El archivo `.env` está en `.gitignore` y **nunca debe subirse al repositorio**:
```bash
cp .env.example .env
```
Usa `APP_ENV=development` en local para ver detalles de errores y `APP_ENV=production` en el servidor.

### Paso 2: Crear las Tablas (migraciones)
Crea una base de datos vacía (`utf8mb4`) y ejecuta las migraciones versionadas de `database/migrations/`:
```bash
php database/migrate.php
```
Comandos útiles:
```bash
php database/migrate.php --status
```
```bash
php database/migrate.php --seed
```
- `--status` muestra qué migraciones están aplicadas y cuáles pendientes (se registran en la tabla `schema_migrations`).
- `--seed` carga datos de demostración (solo con `APP_ENV=development`).
- Para un cambio de esquema nuevo, crea `database/migrations/NNNN_descripcion.sql` con el siguiente número y vuelve a ejecutar `php database/migrate.php`.
- Los recordatorios de vencimiento se envían con `php database/reminders.php`; prográmalo por cron una vez al día.
- Los webhooks salientes se procesan con `php database/webhooks.php`; prográmalo cada minuto para reintentos y entregas puntuales.
- El ejecutor solo funciona por línea de comandos; no es accesible desde el navegador.

### Inicio de sesión con Google
El botón **Continuar con Google** aparece automáticamente cuando están configuradas las credenciales.

1. Crea un cliente OAuth 2.0 en [Google Cloud Console → Credentials](https://console.cloud.google.com/apis/credentials).
2. En *Authorized redirect URIs* añade la URL exacta:
   - Producción: `https://verticeplatform.com/google-callback`
   - Local: `http://localhost:8000/google-callback` (o el puerto que uses).
3. Añade en `.env`:
```env
GOOGLE_CLIENT_ID=tu-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=tu-client-secret
# Opcional; por defecto APP_URL/google-callback
GOOGLE_REDIRECT_URI=
```
4. Reinicia PHP/Apache para recargar `.env`.

El acceso solo permite usuarios ya registrados con el correo verificado. La primera vez vincula el `sub` de Google a la cuenta; si la cuenta ya está vinculada a otro Google, se rechaza. Si el usuario tiene 2FA activo, Google no lo omite: se le pide el código TOTP igualmente. El login personalizado de agencia (`/slug`) también incluye el botón y valida que el usuario pertenezca a esa agencia.

### API para integraciones externas
Los administradores pueden crear tokens en **Administración → Integraciones API** (`/api-tokens`). Cada token tiene permisos (`projects:read`, `tickets:read`, `tickets:write`), proyectos permitidos, caducidad y límite de peticiones por minuto. El token completo solo se muestra una vez; se guarda hasheado.

Autenticación:
```http
Authorization: Bearer vrt_...
Content-Type: application/json
```

Endpoints:
- `GET /api/v1/projects`: proyectos permitidos por el token.
- `GET /api/v1/tickets?project_id=...&status=...&updated_since=...`: tickets.
- `POST /api/v1/tickets`: registra un ticket en un proyecto.
- `GET /api/v1/tickets/{id}`: consulta un ticket.
- `PATCH /api/v1/tickets/{id}`: actualiza estado, prioridad, título, descripción o URL externa.

Ejemplo para registrar un ticket desde otro sistema:
```bash
curl -X POST https://verticeplatform.com/api/v1/tickets \
  -H "Authorization: Bearer vrt_tu_token" \
  -H "Content-Type: application/json" \
  -d '{
    "project_id": 105,
    "title": "No carga el panel",
    "description": "El cliente reporta un error al entrar.",
    "priority": "alta",
    "requester_name": "Ana Torres",
    "requester_email": "ana@empresa.com",
    "external_id": "ZD-12345",
    "source": "zendesk"
  }'
```

Si se repite `external_id` + `source` en el mismo proyecto, el endpoint devuelve el ticket existente en lugar de duplicarlo. Los errores usan códigos HTTP estándar y un cuerpo `{"error":{"code","message"}}`. El límite por minuto se aplica por token.

#### Webhooks salientes
Cada token puede configurar una URL de webhook y los eventos que quiere recibir:
- `ticket.created`
- `ticket.updated`
- `ticket.status_changed`
- `ticket.deleted`

Los eventos de tickets modificados desde Vértice se encolan y se envían automáticamente al sistema externo. Cada petición incluye:
```http
X-Vertice-Event: ticket.status_changed
X-Vertice-Event-Id: evt_...
X-Vertice-Delivery: 123
X-Vertice-Timestamp: 1794326400
X-Vertice-Signature: sha256=...
Content-Type: application/json
```
La firma se calcula con `HMAC_SHA256(secreto, timestamp + "." + cuerpo)`. El secreto se muestra en la configuración del token. Las entregas fallidas se reintentan con backoff exponencial hasta 8 veces y se pueden revisar o reenviar desde `/api-tokens`.

### Permisos de módulos por rol
Los administradores pueden decidir qué módulos ve cada rol en **Configuración → Permisos** (`/settings?tab=permisos`).

- `admin`: acceso total permanente; no se puede restringir.
- `project_manager`: puede recibir los módulos operativos y financieros (tareas, calendario, CRM, cotizaciones, facturación, cobros, reportes, rentabilidad, plantillas, papelera y soporte).
- `colaborador` y `cliente`: solo dashboard, tareas, calendario y configuración.

Reglas de seguridad:
- El administrador únicamente puede **restringir** módulos dentro del techo máximo de cada rol; nunca conceder accesos fuera de ese techo.
- `dashboard` y `settings` son módulos núcleo y permanecen siempre activos para evitar bloqueos.
- Los módulos `users`, `connections` y `api-tokens` siguen siendo exclusivos de administradores.
- El cambio se aplica de inmediato y se valida en el servidor: sidebar, páginas, acciones POST y APIs comprueban el mismo permiso. Ocultar un enlace no es la única defensa.
- El dashboard también se compone según los módulos: si `tasks` está desactivado se ocultan sus KPIs y gráficos; si `finance` está desactivado se oculta el resumen financiero. El resumen financiero de un PM solo suma los proyectos que puede ver.
- El acceso a cada proyecto sigue dependiendo de la asignación del usuario en `project_members` o de su rol; habilitar un módulo no otorga acceso a proyectos ajenos.

Los valores por defecto reproducen el comportamiento anterior, por lo que ningún usuario pierde accesos hasta que el administrador los modifique.

### Caja (ingresos y egresos)
**Finanzas → Caja** (`/cash`) es un libro de caja independiente de facturas y cuentas por pagar:

- **Cuentas**: efectivo, banco, tarjeta u otra, con moneda y saldo inicial. El saldo actual se calcula con la apertura más todos los movimientos.
- **Categorías**: propias para ingresos y egresos. Las categorías con movimientos se desactivan, no se eliminan.
- **Movimientos**: fecha, tipo, importe, cuenta, categoría, proyecto opcional, descripción y referencia.
- **Resumen**: saldo por cuenta, ingresos, egresos y resultado neto del periodo, con filtros por fecha, tipo, cuenta, categoría, proyecto y texto.
- **Gráfico**: comparativa mensual de ingresos y egresos (últimos 6 meses) por moneda.
- **Automatización**: al marcar una factura como cobrada se crea un ingreso, y al registrar un pago de una cuenta por pagar se crea un egreso. Las reglas se configuran por tipo y moneda (cuenta y categoría). Cada origen genera un solo movimiento mediante una clave única; al volver la factura a pendiente o eliminar el pago, el movimiento se retira. La pestaña **Automatización** permite revisar y quitar movimientos generados sin cambiar el estado del origen.

Los movimientos manuales siguen siendo independientes de facturas y cuentas por pagar para evitar duplicar importes. Los movimientos automáticos se marcan como tales y no se editan desde el listado para no desincronizar su origen.

El módulo aparece como `cash` en **Configuración → Permisos** y está disponible para administradores y project managers. Al registrarse, cada agencia recibe una cuenta "Caja general" y categorías iniciales que puede editar.

### Correo saliente (SMTP) y verificación de cuentas
Configura en `.env` las credenciales del buzón (por ejemplo Hostinger):

```env
MAIL_FROM=info@tudominio.com
MAIL_FROM_NAME=Vértice
SMTP_HOST=smtp.hostinger.com
SMTP_PORT=465
SMTP_USER=info@tudominio.com
SMTP_PASS=tu-contraseña
SMTP_SECURE=ssl
```

- Si hay `SMTP_HOST`, el envío es por SMTP autenticado (465 con SSL o 587 con STARTTLS). Sin SMTP se usa `mail()`; con `MAIL_ENABLED=false` los correos se escriben en `storage/mail.log`.
- Al registrarse se envía un enlace de verificación (`/verify-email?token=…`, válido 48 h y de un solo uso) y la cuenta queda pendiente hasta abrirlo. El aviso del menú lateral permite reenviar el enlace.
- Las invitaciones y las cuentas creadas por un administrador se consideran verificadas; las cuentas anteriores a esta función también.
- La verificación no bloquea el inicio de sesión: se puede trabajar y verificar más tarde desde `/verify-email`.

### Paso 3: Iniciar la Aplicación
Puedes colocar esta carpeta dentro de `htdocs` (en XAMPP) o `www` (en Laragon), o bien ejecutar directamente el servidor integrado de PHP en esta carpeta:
```bash
php -S localhost:8000
```
Luego abre tu navegador en:
```
http://localhost:8000
```
La raíz muestra la **landing pública** y la aplicación vive en `/app` (front React, ver más abajo). PHP es solo el backend: la API JSON de React (`api/app/`), los endpoints compartidos de `api/`, la API externa `/api/v1/` (autenticada con `Authorization: Bearer`), el login con Google (`/google-login`, `/google-callback`), las descargas (`/quote-pdf?id=N`, `/export`, `/report-download`), el sitemap y las páginas públicas (`/comparar/*`, `/soluciones/*`, `/agencia-demo`). Las URLs no llevan extensión (Apache/LiteSpeed con `mod_rewrite`); con el servidor embebido de PHP, que ignora `.htaccess`, usa las rutas con `.php`.

Las rutas del antiguo front PHP (`/dashboard`, `/project/3`, `/invoices`, `/crm-company?id=N`, `/quote-view?id=N`, ...) redirigen con 301 a su pantalla en `/app`, porque siguen apareciendo en correos ya enviados y marcadores (ver `.htaccess`).

---

## 🔑 Credenciales de Demostración
Si cargaste los datos demo con `--seed` (solo en local), hay dos agencias: *Agencia Demo*, con cuentas para los cuatro roles (`admin@demo.com`, `pm@demo.com`, `colaborador@demo.com`, `cliente@demo.com`), y *Estudio Norte* (`admin@norte.com`, `colaborador@norte.com`), útil para comprobar que una agencia no ve el contenido de otra. La contraseña está indicada al inicio de `database/seeds/demo.sql`. **No cargues los datos demo en producción.**

---

## 🧑‍💻 Desarrollo

### Estructura
- `src/` contiene las clases del namespace `App\` (PSR-4): `Http` (Request, Router), `Controller`, `Repository` (proyectos, tareas, agencias, invitaciones), `Service` (altas de cuentas), `Security` (permisos, CSRF, límite de intentos de login), `Upload`, `Database` (migraciones) y `Support`.
- `config/app.php` mantiene las funciones globales de siempre (`canManageTasks()`, `csrf_token()`, ...) como fachada sobre esas clases.
- `api/tasks.php` usa el patrón Router + Controller (`App\Controller\TaskController`). El resto de endpoints de `api/` sigue siendo procedural y se puede migrar al mismo patrón.
- `api/app/` es la API JSON del front React; `api/app/_bootstrap.php` exige sesión y CSRF y convierte los errores de validación (`DomainException`) en respuestas 422.

### Dependencias de desarrollo
Composer solo hace falta en desarrollo. En producción no hay que ejecutar `composer install` (existe un autoloader de respaldo en `config/autoload.php`).
```bash
composer install
```

### Interfaz React
La interfaz vive en `front/` y se sirve en `/app`:

```bash
cd front
npm install
npm run dev      # desarrollo con proxy al PHP local
npm run build    # genera front/dist para producción
```

- React 19, TypeScript, Vite, React Router, TanStack Query y Tailwind.
- Usa la misma sesión PHP y exige CSRF en las escrituras.
- La API JSON está en `api/app/`.
- Cubre toda la aplicación: dashboard, proyectos, tareas, calendario, CRM, caja, facturación, cobros/pagos, rentabilidad, reportes, configuración, usuarios, cotizaciones, plantillas, papelera, soporte, agencias conectadas, API tokens, webhooks, marca, autenticación, landing y legales. El antiguo front PHP se eliminó.
- Arquitectura y convenciones: `front/README.md`.

### Tests
Los tests unitarios no necesitan base de datos:
```bash
vendor/bin/phpunit --testsuite unit
```
Los de integración usan una base MySQL desechable: **la base indicada en `TEST_DB_NAME` se borra y se recrea en cada ejecución**, por eso su nombre debe contener `test`. Sin `TEST_DB_NAME` se omiten. Los datos de conexión se toman de `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_USER` y `TEST_DB_PASS` (por defecto `127.0.0.1:3306`, `root`, sin contraseña; ver `phpunit.xml`).
```bash
TEST_DB_NAME=vertice_phpunit_test vendor/bin/phpunit
```
