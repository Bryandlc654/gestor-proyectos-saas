// Páginas de solución (/soluciones/<slug>): clúster SEO alrededor de la keyword pilar
// "software de gestión de proyectos para agencias". Cada página ataca una keyword propia
// (función o sector), tiene contenido único y enlaza a la home, a páginas hermanas y a comparativas.
// Se prerenderizan como HTML estático en el build (ver scripts/prerender.mjs).

import { ERP_SOLUTIONS } from './erp-solution-data';

export type SolutionKind = 'funcion' | 'sector';
export type SolutionProduct = 'agency' | 'erp';

export type SolutionPage = {
  slug: string;
  /** App a la que pertenece la página (por defecto, Vértice Agency). */
  product?: SolutionProduct;
  kind: SolutionKind;
  /** Texto corto para enlaces internos (anchor text con la keyword). */
  name: string;
  /** Meta title (≤ 60 caracteres aprox.) y meta description (≤ 155). */
  title: string;
  description: string;
  eyebrow: string;
  /** H1 = lead + highlight; debe contener la keyword principal. */
  h1Lead: string;
  h1Highlight: string;
  intro: string;
  painsTitle: string;
  pains: { t: string; d: string }[];
  featuresTitle: string;
  features: { t: string; d: string }[];
  stepsTitle: string;
  steps: { t: string; d: string }[];
  faqs: { q: string; a: string }[];
  related: string[];
  compare: string[];
};

const AGENCY_SOLUTIONS: SolutionPage[] = [
  /* ---------------------------------------------------------------- */
  /* Funciones                                                         */
  /* ---------------------------------------------------------------- */
  {
    slug: 'crm-para-agencias',
    kind: 'funcion',
    name: 'CRM para agencias',
    title: 'CRM para agencias: pipeline, clientes y propuestas | Vértice',
    description: 'CRM para agencias con pipeline por etapas, contactos, actividades y cotizaciones en PDF que se convierten en proyectos. Empieza gratis.',
    eyebrow: 'CRM para agencias',
    h1Lead: 'CRM para agencias que convierte',
    h1Highlight: 'propuestas en proyectos.',
    intro: 'Gestiona empresas, contactos y oportunidades en un pipeline comercial pensado para agencias. Cuando el cliente acepta, conviertes la oportunidad en proyecto con su presupuesto, sin copiar datos entre herramientas.',
    painsTitle: 'Lo que resuelve un CRM hecho para agencias',
    pains: [
      { t: 'Oportunidades en hojas de cálculo', d: 'Los seguimientos se pierden entre correos, chats y archivos sueltos, y nadie sabe en qué quedó cada propuesta.' },
      { t: 'Ventas desconectadas de la operación', d: 'Lo vendido llega al equipo de producción sin contexto, sin alcance y sin presupuesto.' },
      { t: 'Sin visibilidad del pipeline', d: 'No sabes cuánto tienes en negociación, qué etapa se atasca ni qué se cerrará este mes.' },
    ],
    featuresTitle: 'Funciones del CRM de Vértice',
    features: [
      { t: 'Pipeline comercial por etapas', d: 'Mueve cada oportunidad por las etapas de tu proceso de venta y consulta su valor estimado.' },
      { t: 'Empresas y contactos', d: 'Una ficha por cliente con sus contactos, oportunidades, propuestas y actividades recientes.' },
      { t: 'Actividades de seguimiento', d: 'Registra llamadas, reuniones y próximos pasos para que ningún seguimiento quede atrás.' },
      { t: 'Cotizaciones en PDF', d: 'Crea propuestas con la marca de tu agencia y controla si están enviadas, aceptadas o rechazadas.' },
      { t: 'De oportunidad a proyecto', d: 'Convierte una oportunidad ganada en proyecto usando su valor estimado como presupuesto inicial.' },
      { t: 'Hasta la factura cobrada', d: 'El proyecto continúa con tareas, facturas, cobros y rentabilidad en la misma plataforma.' },
    ],
    stepsTitle: 'Del primer contacto al proyecto',
    steps: [
      { t: 'Registra la oportunidad', d: 'Crea la empresa, sus contactos y la oportunidad con su valor estimado.' },
      { t: 'Envía la cotización', d: 'Genera el PDF con tu marca y haz seguimiento desde el pipeline.' },
      { t: 'Conviértela en proyecto', d: 'Al ganarla, el proyecto nace con cliente y presupuesto listos.' },
    ],
    faqs: [
      { q: '¿Qué es un CRM para agencias?', a: 'Es un CRM adaptado a cómo vende una agencia: oportunidades que se convierten en propuestas y, al aceptarse, en proyectos con presupuesto. En Vértice el CRM está conectado con proyectos, facturación y rentabilidad.' },
      { q: '¿Puedo convertir una oportunidad en proyecto?', a: 'Sí. Desde la ficha de la empresa conviertes la oportunidad ganada en un proyecto que usa el valor estimado como presupuesto inicial.' },
      { q: '¿El CRM tiene un coste adicional?', a: 'No es un producto aparte: el CRM forma parte de Vértice junto con proyectos, cotizaciones y facturación. Puedes crear tu cuenta gratis.' },
      { q: '¿Mis clientes ven la información comercial?', a: 'No. El CRM y las finanzas son privados de tu agencia. Los clientes y las agencias aliadas solo ven los proyectos que compartes.' },
    ],
    related: ['facturacion-para-agencias', 'agencias-de-marketing', 'consultoras'],
    compare: ['monday', 'odoo', 'clickup'],
  },
  {
    slug: 'facturacion-para-agencias',
    kind: 'funcion',
    name: 'Cotizaciones y facturación',
    title: 'Software de facturación y cotizaciones para agencias | Vértice',
    description: 'Cotizaciones en PDF, facturas con pagos parciales, cuentas por pagar, caja y rentabilidad por proyecto para agencias. Pruébalo gratis.',
    eyebrow: 'Cotizaciones y facturación',
    h1Lead: 'Cotizaciones y facturación para agencias,',
    h1Highlight: 'conectadas a cada proyecto.',
    intro: 'Cotiza con tu marca, factura por proyecto, registra abonos y controla la caja. Al final de cada proyecto sabes cuánto ganaste de verdad.',
    painsTitle: 'El problema de facturar fuera de tus proyectos',
    pains: [
      { t: 'Cobros que nadie sigue', d: 'Facturas pendientes y pagos parciales repartidos entre el banco, una hoja de cálculo y la memoria del equipo.' },
      { t: 'Proyectos que parecen rentables', d: 'Sin cruzar ingresos y costos por proyecto, descubres tarde que un cliente te cuesta más de lo que paga.' },
      { t: 'Propuestas hechas a mano', d: 'Cada cotización se arma desde cero en un documento distinto, sin seguimiento de su estado.' },
    ],
    featuresTitle: 'Todo el ciclo financiero de tu agencia',
    features: [
      { t: 'Cotizaciones en PDF', d: 'Propuestas con tu logo y datos de la agencia, con seguimiento de estado y conversión a proyecto.' },
      { t: 'Facturas por proyecto', d: 'Registra cada factura vinculada a su proyecto y cliente, y adjunta los documentos que emites.' },
      { t: 'Pagos parciales y saldos', d: 'Anota abonos y el saldo y el estado de la factura se recalculan solos.' },
      { t: 'Cuentas por pagar', d: 'Controla lo que debes a proveedores y freelancers, y registra cada pago.' },
      { t: 'Caja automática', d: 'Cada cobro de factura crea un ingreso y cada pago a proveedor un egreso, por categorías y cuentas.' },
      { t: 'Rentabilidad por proyecto', d: 'Compara ingresos y costos de cada proyecto y detecta a tiempo los que pierden margen.' },
    ],
    stepsTitle: 'De la propuesta al margen real',
    steps: [
      { t: 'Cotiza', d: 'Envía una propuesta en PDF y conviértela en proyecto al aceptarse.' },
      { t: 'Factura y cobra', d: 'Registra facturas y abonos; la caja se actualiza sola.' },
      { t: 'Mide', d: 'Revisa la rentabilidad de cada proyecto y de tu agencia.' },
    ],
    faqs: [
      { q: '¿Puedo registrar pagos parciales de una factura?', a: 'Sí. Registras cada abono y Vértice recalcula el saldo pendiente y el estado de la factura. Cada cobro también se registra en la caja.' },
      { q: '¿Las cotizaciones llevan mi marca?', a: 'Sí. Las cotizaciones se generan en PDF con el logo y los datos de tu agencia.' },
      { q: '¿Cómo calcula Vértice la rentabilidad de un proyecto?', a: 'Cruza los ingresos facturados del proyecto con sus costos y egresos asociados para mostrarte el margen de cada proyecto.' },
      { q: '¿Las agencias aliadas o los clientes ven mis facturas?', a: 'No. Presupuestos, facturas y caja nunca se comparten con agencias conectadas ni con clientes.' },
    ],
    related: ['crm-para-agencias', 'consultoras', 'empresas-de-software'],
    compare: ['odoo', 'monday', 'asana'],
  },
  {
    slug: 'diagrama-de-gantt',
    kind: 'funcion',
    name: 'Diagrama de Gantt online',
    title: 'Diagrama de Gantt online para proyectos de agencia | Vértice',
    description: 'Diagrama de Gantt online con dependencias, hitos y camino crítico, junto a Kanban y lista sobre las mismas tareas. Incluido gratis en Vértice.',
    eyebrow: 'Diagrama de Gantt',
    h1Lead: 'Diagrama de Gantt online',
    h1Highlight: 'para planificar sin sorpresas.',
    intro: 'Planifica fechas, encadena dependencias y sigue el camino crítico de cada proyecto. El mismo trabajo se ve en Gantt, Kanban o lista, sin duplicar tareas.',
    painsTitle: 'Por qué un Kanban no alcanza',
    pains: [
      { t: 'Retrasos en cadena', d: 'Una tarea que se mueve arrastra a otras, y en un tablero no ves a cuáles hasta que es tarde.' },
      { t: 'Fechas de entrega optimistas', d: 'Sin ver la secuencia real de trabajo, comprometes entregas que el equipo no puede cumplir.' },
      { t: 'Gantt en otra herramienta', d: 'Mantener el cronograma en una hoja aparte significa que siempre está desactualizado.' },
    ],
    featuresTitle: 'Un Gantt pensado para proyectos de clientes',
    features: [
      { t: 'Dependencias entre tareas', d: 'Encadena tareas para que el cronograma refleje el orden real del trabajo.' },
      { t: 'Camino crítico', d: 'Identifica las tareas que, si se retrasan, mueven la fecha de entrega del proyecto.' },
      { t: 'Hitos', d: 'Marca entregas y aprobaciones clave del cliente dentro del cronograma.' },
      { t: 'Arrastrar y soltar', d: 'Cambia fechas y duraciones directamente sobre las barras del diagrama.' },
      { t: 'Kanban y lista sincronizados', d: 'Las mismas tareas, con sus estados propios, en la vista que prefiera cada persona.' },
      { t: 'Subtareas y plantillas', d: 'Divide el trabajo en subtareas y checklists, y reutiliza plantillas de proyecto.' },
    ],
    stepsTitle: 'Planifica un proyecto en minutos',
    steps: [
      { t: 'Parte de una plantilla', d: 'Crea el proyecto desde una plantilla o desde cero.' },
      { t: 'Encadena el trabajo', d: 'Define fechas, dependencias e hitos en el Gantt.' },
      { t: 'Sigue el avance', d: 'Tu equipo actualiza tareas en Kanban y el cronograma se mantiene al día.' },
    ],
    faqs: [
      { q: '¿El diagrama de Gantt tiene coste adicional?', a: 'No. El Gantt con dependencias, hitos y camino crítico está incluido en Vértice, y puedes empezar gratis.' },
      { q: '¿Puedo ver las mismas tareas en Kanban?', a: 'Sí. Gantt, Kanban y lista son vistas de las mismas tareas: lo que cambias en una se refleja en las demás.' },
      { q: '¿Mis clientes pueden ver el cronograma?', a: 'Tus clientes ven el avance de su proyecto, sus tareas y el calendario desde su acceso limitado, sin ver comentarios internos ni finanzas.' },
    ],
    related: ['empresas-de-software', 'estudios-de-diseno', 'portal-de-clientes'],
    compare: ['trello', 'asana', 'clickup'],
  },
  {
    slug: 'portal-de-clientes',
    kind: 'funcion',
    name: 'Portal de clientes',
    title: 'Portal de clientes para agencias con acceso limitado | Vértice',
    description: 'Da a tus clientes un portal para ver el avance de sus proyectos, sus tareas y el calendario, y abrir tickets, sin ver tus finanzas ni notas internas.',
    eyebrow: 'Portal de clientes',
    h1Lead: 'Portal de clientes para agencias:',
    h1Highlight: 'transparencia sin exponer.',
    intro: 'Invita a cada cliente a ver el avance de su proyecto, sus tareas y el calendario, y a abrir tickets de soporte. Tus comentarios internos, checklists y finanzas siguen siendo privados.',
    painsTitle: 'Menos “¿cómo va lo mío?”',
    pains: [
      { t: 'Reportes de avance a mano', d: 'Cada semana armas capturas y correos para contar a cada cliente en qué está su proyecto.' },
      { t: 'Solicitudes por WhatsApp', d: 'Cambios y errores llegan por cualquier canal y se pierden sin registro ni responsable.' },
      { t: 'Invitados con demasiado acceso', d: 'En herramientas generalistas, invitar a un cliente puede exponer conversaciones o datos que no debería ver.' },
    ],
    featuresTitle: 'Qué ve tu cliente (y qué no)',
    features: [
      { t: 'Dashboard del cliente', d: 'Un resumen del avance de sus proyectos al entrar.' },
      { t: 'Sus tareas y calendario', d: 'Ve las tareas de su proyecto y las fechas importantes.' },
      { t: 'Tickets de soporte', d: 'Abre solicitudes con respuestas e imágenes, en un canal ordenado.' },
      { t: 'Privacidad por defecto', d: 'No ve comentarios internos, checklists, CRM ni finanzas.' },
      { t: 'Invitación segura', d: 'Enlace de un solo uso que caduca en 7 días, con rol de cliente.' },
      { t: 'También para agencias aliadas', d: 'Comparte proyectos con otras agencias con acceso de Ver o Colaborar.' },
    ],
    stepsTitle: 'Invita a tu cliente en tres pasos',
    steps: [
      { t: 'Crea el enlace', d: 'Genera una invitación con rol de cliente.' },
      { t: 'Tu cliente se registra', d: 'Entra con su cuenta, sin instalar nada.' },
      { t: 'Ve solo lo suyo', d: 'Sigue su proyecto y abre tickets cuando lo necesite.' },
    ],
    faqs: [
      { q: '¿Qué puede ver un cliente en Vértice?', a: 'Su dashboard, las tareas de su proyecto y el calendario. Además puede abrir tickets. No ve comentarios internos, checklists, CRM ni finanzas.' },
      { q: '¿Cómo invito a un cliente?', a: 'Con un enlace de un solo uso que caduca en 7 días. Al registrarse queda con el rol de cliente y acceso limitado.' },
      { q: '¿El portal de clientes tiene coste adicional?', a: 'No. El acceso para clientes forma parte de Vértice y puedes empezar gratis.' },
      { q: '¿Puedo compartir un proyecto con otra agencia?', a: 'Sí. Las agencias se conectan por código y comparten proyectos con acceso de Ver o Colaborar, sin exponer presupuestos ni facturas.' },
    ],
    related: ['agencias-de-marketing', 'estudios-de-diseno', 'diagrama-de-gantt'],
    compare: ['clickup', 'trello', 'monday'],
  },

  /* ---------------------------------------------------------------- */
  /* Sectores                                                          */
  /* ---------------------------------------------------------------- */
  {
    slug: 'agencias-de-marketing',
    kind: 'sector',
    name: 'Agencias de marketing',
    title: 'Gestión de proyectos para agencias de marketing | Vértice',
    description: 'Software de gestión para agencias de marketing digital: campañas, tareas, CRM, cotizaciones, facturación y portal de clientes en un solo lugar.',
    eyebrow: 'Para agencias de marketing',
    h1Lead: 'Gestión de proyectos para',
    h1Highlight: 'agencias de marketing.',
    intro: 'Organiza campañas, contenidos y cuentas recurrentes; vende con un CRM propio y factura sin perder el control del margen de cada proyecto.',
    painsTitle: 'El día a día de una agencia de marketing digital',
    pains: [
      { t: 'Muchos clientes, muchos frentes', d: 'Redes, pauta, contenido y web para varios clientes a la vez, con fechas que chocan.' },
      { t: 'Fees mensuales sin control', d: 'Cuentas recurrentes que consumen más horas y costos de lo que pagan.' },
      { t: 'Aprobaciones eternas', d: 'El cliente pregunta por el avance y las solicitudes llegan por cualquier canal.' },
    ],
    featuresTitle: 'Cómo ayuda Vértice a tu agencia de marketing',
    features: [
      { t: 'Campañas como proyectos', d: 'Cada campaña o cuenta con sus tareas, responsables y fechas en Kanban o Gantt.' },
      { t: 'Plantillas reutilizables', d: 'Lanzamientos, parrillas de contenido o auditorías listas para repetir.' },
      { t: 'CRM y propuestas', d: 'Pipeline comercial y cotizaciones en PDF para captar nuevas cuentas.' },
      { t: 'Facturas y cobros', d: 'Registra las facturas de cada cuenta, sus abonos y la caja.' },
      { t: 'Rentabilidad por proyecto', d: 'Compara lo que cobras con lo que cuesta cada cuenta o campaña.' },
      { t: 'Portal y tickets', d: 'Tus clientes ven el avance y piden cambios por tickets, no por WhatsApp.' },
    ],
    stepsTitle: 'Pon tu agencia en marcha hoy',
    steps: [
      { t: 'Configura tu agencia', d: 'Marca, estados de tareas y roles de tu equipo.' },
      { t: 'Carga tus cuentas', d: 'Crea los proyectos de cada cliente o parte de una plantilla.' },
      { t: 'Invita a tus clientes', d: 'Con acceso limitado a su avance y sus tickets.' },
    ],
    faqs: [
      { q: '¿Vértice sirve para una agencia de marketing digital?', a: 'Sí. Está pensado para agencias: gestionas campañas y cuentas como proyectos, vendes con un CRM, cotizas en PDF, facturas y mides la rentabilidad de cada proyecto.' },
      { q: '¿Puedo trabajar con freelancers o agencias aliadas?', a: 'Sí. Puedes añadir usuarios con roles y permisos por módulo, o conectar agencias aliadas y compartir proyectos con acceso de Ver o Colaborar.' },
      { q: '¿Cuánto cuesta empezar?', a: 'Puedes crear tu cuenta gratis y configurar tu agencia en minutos, sin instalar nada.' },
    ],
    related: ['crm-para-agencias', 'portal-de-clientes', 'facturacion-para-agencias'],
    compare: ['clickup', 'monday', 'trello'],
  },
  {
    slug: 'estudios-de-diseno',
    kind: 'sector',
    name: 'Estudios de diseño',
    title: 'Gestión de proyectos para estudios de diseño | Vértice',
    description: 'Gestión de proyectos para estudios de diseño y creativos: briefs, revisiones, entregas, cotizaciones y facturación con portal de clientes.',
    eyebrow: 'Para estudios creativos',
    h1Lead: 'Gestión de proyectos para',
    h1Highlight: 'estudios de diseño.',
    intro: 'Del brief a la entrega final: organiza rondas de revisión, archivos y fechas, cotiza cada proyecto y cobra sin perseguir a tus clientes.',
    painsTitle: 'Lo que frena a un estudio creativo',
    pains: [
      { t: 'Rondas de cambios infinitas', d: 'Las revisiones se acumulan sin registro y el proyecto se alarga sin cobrar más.' },
      { t: 'Archivos y feedback dispersos', d: 'Versiones en el correo, comentarios en el chat y entregas en una carpeta compartida.' },
      { t: 'Cotizar lleva demasiado', d: 'Cada propuesta se arma desde cero y el seguimiento depende de la memoria.' },
    ],
    featuresTitle: 'Hecho para el flujo de un estudio',
    features: [
      { t: 'Del brief a la entrega', d: 'Etapas de tu proceso creativo como estados propios en Kanban.' },
      { t: 'Archivos y comentarios', d: 'Adjuntos y conversación de cada tarea en un mismo lugar.' },
      { t: 'Cronograma con hitos', d: 'Presentaciones y aprobaciones del cliente marcadas en el Gantt.' },
      { t: 'Cotizaciones con tu marca', d: 'Propuestas en PDF que se convierten en proyecto al aceptarse.' },
      { t: 'Cobros y abonos', d: 'Anticipos y pagos parciales registrados por factura.' },
      { t: 'Cliente informado', d: 'Portal con su avance y tickets para pedir ajustes con orden.' },
    ],
    stepsTitle: 'Tu estudio, organizado en una tarde',
    steps: [
      { t: 'Define tu proceso', d: 'Crea los estados: brief, bocetos, revisión, final.' },
      { t: 'Crea una plantilla', d: 'Reutilízala en cada proyecto de branding o web.' },
      { t: 'Cotiza y entrega', d: 'De la propuesta a la factura cobrada en el mismo lugar.' },
    ],
    faqs: [
      { q: '¿Vértice sirve para un estudio de diseño pequeño?', a: 'Sí. Funciona igual para estudios de pocas personas que para equipos más grandes, con roles y permisos por módulo.' },
      { q: '¿Puedo registrar anticipos de un proyecto?', a: 'Sí. Las facturas admiten pagos parciales, de modo que puedes registrar el anticipo y los pagos siguientes.' },
      { q: '¿El cliente puede pedir cambios dentro de la plataforma?', a: 'Sí. Con su acceso de cliente puede abrir tickets con texto e imágenes, sin ver tus comentarios internos ni finanzas.' },
    ],
    related: ['portal-de-clientes', 'facturacion-para-agencias', 'diagrama-de-gantt'],
    compare: ['trello', 'asana', 'clickup'],
  },
  {
    slug: 'empresas-de-software',
    kind: 'sector',
    name: 'Empresas de software',
    title: 'Gestión de proyectos para empresas de software | Vértice',
    description: 'Gestión de proyectos para software factories: Gantt con dependencias, tickets de soporte, API REST, webhooks y facturación por proyecto.',
    eyebrow: 'Para software factories',
    h1Lead: 'Gestión de proyectos para',
    h1Highlight: 'empresas de software.',
    intro: 'Planifica desarrollos con dependencias y camino crítico, atiende el soporte de tus clientes con tickets y conecta Vértice a tu stack con API y webhooks.',
    painsTitle: 'Desarrollar para clientes es más que escribir código',
    pains: [
      { t: 'Alcance que crece solo', d: 'Sin conectar la propuesta con el proyecto, cada cambio se absorbe sin facturarse.' },
      { t: 'Soporte sin orden', d: 'Errores y solicitudes post-lanzamiento llegan por correo, chat y llamadas.' },
      { t: 'Herramientas aisladas', d: 'El gestor de tareas no habla con la facturación ni con tus sistemas internos.' },
    ],
    featuresTitle: 'Lo que tu software factory necesita',
    features: [
      { t: 'Gantt y camino crítico', d: 'Dependencias entre fases y tareas para fechas de entrega realistas.' },
      { t: 'Kanban con estados propios', d: 'Modela tu flujo de desarrollo, QA y despliegue.' },
      { t: 'Tickets de soporte', d: 'Tus clientes reportan incidencias con imágenes en un canal ordenado.' },
      { t: 'API REST y webhooks', d: 'Tokens por agencia y eventos firmados para integrar tu stack.' },
      { t: 'Propuesta a proyecto', d: 'Cotiza el desarrollo y conviértelo en proyecto con su presupuesto.' },
      { t: 'Rentabilidad por proyecto', d: 'Ingresos frente a costos para saber qué proyectos dejan margen.' },
    ],
    stepsTitle: 'Integra Vértice en tu operación',
    steps: [
      { t: 'Modela tu flujo', d: 'Estados, roles y plantillas de proyecto.' },
      { t: 'Conecta tu stack', d: 'Crea un token y suscríbete a webhooks.' },
      { t: 'Abre el soporte', d: 'Invita a tus clientes a reportar por tickets.' },
    ],
    faqs: [
      { q: '¿Vértice tiene API?', a: 'Sí. Vértice ofrece una API REST con tokens por agencia y webhooks firmados para enviar eventos a tus sistemas.' },
      { q: '¿Sirve para gestionar el soporte después del lanzamiento?', a: 'Sí. Tus clientes abren tickets con texto e imágenes y tu equipo los responde dentro de la plataforma.' },
      { q: '¿Reemplaza a Jira?', a: 'Vértice cubre la gestión de proyectos para clientes (planificación, tareas, soporte, cotizaciones y facturación). Si tu equipo necesita funciones avanzadas de desarrollo ágil, puede convivir con tu herramienta de desarrollo mediante la API.' },
    ],
    related: ['diagrama-de-gantt', 'portal-de-clientes', 'facturacion-para-agencias'],
    compare: ['clickup', 'asana', 'odoo'],
  },
  {
    slug: 'consultoras',
    kind: 'sector',
    name: 'Consultoras y freelancers',
    title: 'Software de gestión para consultoras y freelancers | Vértice',
    description: 'Software de gestión para consultoras y freelancers: CRM, propuestas en PDF, proyectos, facturación y rentabilidad por proyecto. Empieza gratis.',
    eyebrow: 'Para consultoras y freelancers',
    h1Lead: 'Software de gestión para',
    h1Highlight: 'consultoras y freelancers.',
    intro: 'Vende, entrega y cobra desde una sola herramienta. Lleva tus propuestas, proyectos y facturas juntos y sabe qué proyectos son realmente rentables.',
    painsTitle: 'Cuando vendes conocimiento, el tiempo es el producto',
    pains: [
      { t: 'Demasiadas herramientas', d: 'Una para tareas, otra para propuestas, una hoja para cobros y otra para clientes.' },
      { t: 'Cobros que se retrasan', d: 'Pagos parciales y facturas pendientes que nadie sigue a tiempo.' },
      { t: 'No sabes qué proyecto rinde', d: 'Sin cruzar ingresos y costos, todos los proyectos parecen iguales.' },
    ],
    featuresTitle: 'Todo tu negocio en un solo lugar',
    features: [
      { t: 'CRM sencillo', d: 'Empresas, contactos y oportunidades en un pipeline por etapas.' },
      { t: 'Propuestas en PDF', d: 'Cotizaciones con tu marca que se convierten en proyecto.' },
      { t: 'Proyectos y tareas', d: 'Kanban, lista y Gantt para organizar cada encargo.' },
      { t: 'Facturas y abonos', d: 'Controla saldos pendientes y estados de cobro.' },
      { t: 'Caja y egresos', d: 'Ingresos y gastos por categoría y cuenta.' },
      { t: 'Crece con tu equipo', d: 'Suma colaboradores con roles y permisos por módulo.' },
    ],
    stepsTitle: 'Empieza en minutos',
    steps: [
      { t: 'Crea tu cuenta', d: 'Gratis y sin instalar nada.' },
      { t: 'Carga tus clientes', d: 'Empresas, contactos y oportunidades abiertas.' },
      { t: 'Cotiza y factura', d: 'De la propuesta al cobro en el mismo flujo.' },
    ],
    faqs: [
      { q: '¿Vértice sirve si trabajo solo?', a: 'Sí. Puedes usarlo como freelancer para llevar clientes, propuestas, proyectos y cobros, y sumar colaboradores cuando crezcas.' },
      { q: '¿Puedo dar acceso a mis clientes?', a: 'Sí. Tus clientes pueden ver el avance de su proyecto y abrir tickets, sin ver tus notas internas ni tus finanzas.' },
      { q: '¿Cuánto cuesta empezar?', a: 'Puedes crear tu cuenta gratis y configurar tu espacio de trabajo en minutos.' },
    ],
    related: ['crm-para-agencias', 'facturacion-para-agencias', 'agencias-de-marketing'],
    compare: ['odoo', 'trello', 'asana'],
  },
];

export const SOLUTIONS: SolutionPage[] = [...AGENCY_SOLUTIONS, ...ERP_SOLUTIONS];

export const solutionProduct = (s: SolutionPage): SolutionProduct => s.product ?? 'agency';
/** Funciones y sectores de Vértice Agency. */
export const SOLUTION_FEATURES = AGENCY_SOLUTIONS.filter((s) => s.kind === 'funcion');
export const SOLUTION_SECTORS = AGENCY_SOLUTIONS.filter((s) => s.kind === 'sector');
/** Páginas de Vértice ERP. */
export const SOLUTION_ERP = ERP_SOLUTIONS;
export const solutionHref = (slug: string) => `/soluciones/${slug}`;
