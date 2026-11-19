// Datos de las páginas comparativas (/comparar/<slug>). Se prerenderizan en el build para SEO.
// Criterio: comparar funciones públicas y conocidas de cada plataforma, sin desacreditar.
// "partial" = existe pero depende del plan, de un producto aparte, de plantillas o de integraciones.

export type Support = 'yes' | 'partial' | 'no';

export type CompareRowKey =
  | 'kanban' | 'gantt' | 'crm' | 'quotes' | 'invoicing' | 'profit' | 'clients' | 'network' | 'agencies';

export const COMPARE_ROWS: { key: CompareRowKey; label: string }[] = [
  { key: 'kanban', label: 'Kanban, listas y tareas' },
  { key: 'gantt', label: 'Gantt con dependencias' },
  { key: 'crm', label: 'CRM y pipeline comercial' },
  { key: 'quotes', label: 'Cotizaciones en PDF' },
  { key: 'invoicing', label: 'Facturación y pagos parciales' },
  { key: 'profit', label: 'Rentabilidad por proyecto' },
  { key: 'clients', label: 'Acceso limitado para clientes' },
  { key: 'network', label: 'Proyectos compartidos entre agencias' },
  { key: 'agencies', label: 'Pensado para agencias' },
];

export const VERTICE_SUPPORT: Record<CompareRowKey, Support> = {
  kanban: 'yes', gantt: 'yes', crm: 'yes', quotes: 'yes', invoicing: 'yes', profit: 'yes', clients: 'yes', network: 'yes', agencies: 'yes',
};

export type Competitor = {
  slug: string;
  name: string;
  /** Qué es, en pocas palabras. */
  tagline: string;
  /** Meta title y description de la página. */
  title: string;
  description: string;
  support: Record<CompareRowKey, Support>;
  notes: Partial<Record<CompareRowKey, string>>;
  /** Cuándo encaja mejor la otra plataforma (honestidad = confianza). */
  chooseThem: string[];
  chooseUs: string[];
  faqs: { q: string; a: string }[];
};

export const COMPETITORS: Competitor[] = [
  {
    slug: 'clickup',
    name: 'ClickUp',
    tagline: 'Gestor de trabajo generalista y muy configurable.',
    title: 'Alternativa a ClickUp para agencias | Vértice',
    description: 'Alternativa a ClickUp para agencias: proyectos, Gantt, CRM, cotizaciones y facturación en una sola plataforma, con portal de clientes. Empieza gratis.',
    support: { kanban: 'yes', gantt: 'yes', crm: 'partial', quotes: 'no', invoicing: 'no', profit: 'partial', clients: 'yes', network: 'partial', agencies: 'partial' },
    notes: { crm: 'Con plantillas', profit: 'Con campos y control de tiempo', clients: 'Como invitados', network: 'Como invitados', agencies: 'Generalista' },
    chooseThem: ['Necesitas un gestor de tareas muy configurable para cualquier tipo de equipo.', 'Tus procesos comerciales y de facturación ya viven en otras herramientas.'],
    chooseUs: ['Quieres operación, ventas y cobros en un mismo lugar.', 'Trabajas con agencias aliadas y quieres compartir proyectos sin exponer tus finanzas.'],
    faqs: [
      { q: '¿Vértice es una alternativa a ClickUp?', a: 'Sí. Vértice cubre la gestión de proyectos y tareas con Kanban y Gantt, y suma CRM, cotizaciones en PDF, facturación y rentabilidad pensados para agencias.' },
      { q: '¿Puedo trabajar con otras agencias como en ClickUp?', a: 'En Vértice cada agencia conserva su cuenta: se conectan por código y comparten solo los proyectos que eligen, con acceso de Ver o Colaborar. Presupuestos y facturas nunca se comparten.' },
      { q: '¿Cuánto cuesta empezar con Vértice?', a: 'Puedes crear tu cuenta gratis y configurar tu agencia en minutos, sin instalar nada.' },
    ],
  },
  {
    slug: 'trello',
    name: 'Trello',
    tagline: 'Tableros Kanban simples y visuales.',
    title: 'Alternativa a Trello para agencias | Vértice',
    description: 'Alternativa a Trello para agencias: Kanban más Gantt, CRM, cotizaciones, facturación y portal de clientes en una sola plataforma. Empieza gratis.',
    support: { kanban: 'yes', gantt: 'partial', crm: 'partial', quotes: 'no', invoicing: 'no', profit: 'no', clients: 'yes', network: 'partial', agencies: 'partial' },
    notes: { gantt: 'Vista cronograma en planes de pago', crm: 'Con Power-Ups', clients: 'Como invitados del tablero', network: 'Como invitados', agencies: 'Generalista' },
    chooseThem: ['Solo necesitas tableros Kanban sencillos.', 'Tu equipo es pequeño y no gestiona cotizaciones ni facturas en la misma herramienta.'],
    chooseUs: ['Tus proyectos ya superan el tablero: fechas, dependencias y Gantt.', 'Quieres pasar de la cotización al proyecto y a la factura sin cambiar de herramienta.'],
    faqs: [
      { q: '¿Vértice tiene tableros Kanban como Trello?', a: 'Sí. Vértice incluye tableros Kanban con estados propios, y además vistas de lista, cronograma Gantt con dependencias y checklists.' },
      { q: '¿Puedo dar acceso a mis clientes?', a: 'Sí. Invitas a tu cliente con un enlace de un solo uso. Ve el avance de su proyecto y abre tickets, pero no ve comentarios internos ni finanzas.' },
      { q: '¿Cuánto cuesta empezar con Vértice?', a: 'Puedes crear tu cuenta gratis y configurar tu agencia en minutos, sin instalar nada.' },
    ],
  },
  {
    slug: 'asana',
    name: 'Asana',
    tagline: 'Gestión de trabajo y objetivos para equipos.',
    title: 'Alternativa a Asana para agencias | Vértice',
    description: 'Alternativa a Asana para agencias: proyectos y Gantt más CRM, cotizaciones, facturación y rentabilidad por proyecto. Prueba Vértice gratis.',
    support: { kanban: 'yes', gantt: 'partial', crm: 'partial', quotes: 'no', invoicing: 'no', profit: 'no', clients: 'yes', network: 'partial', agencies: 'partial' },
    notes: { gantt: 'Cronograma en planes de pago', crm: 'Con plantillas', clients: 'Como invitados', network: 'Como invitados', agencies: 'Generalista' },
    chooseThem: ['Gestionas objetivos y portafolios en una empresa grande.', 'Tu prioridad es la coordinación interna entre muchos departamentos.'],
    chooseUs: ['Vives de proyectos para clientes y necesitas saber si son rentables.', 'Quieres CRM, cotizaciones y facturas junto a tus proyectos.'],
    faqs: [
      { q: '¿Vértice es una alternativa a Asana?', a: 'Sí, para agencias. Vértice gestiona proyectos con Kanban, lista y Gantt, y añade el ciclo comercial y financiero: CRM, cotizaciones, facturación y rentabilidad.' },
      { q: '¿Vértice incluye cronograma Gantt?', a: 'Sí. El Gantt con dependencias, hitos y camino crítico está incluido en la plataforma.' },
      { q: '¿Cuánto cuesta empezar con Vértice?', a: 'Puedes crear tu cuenta gratis y configurar tu agencia en minutos, sin instalar nada.' },
    ],
  },
  {
    slug: 'odoo',
    name: 'Odoo',
    tagline: 'Suite ERP modular para todo tipo de empresas.',
    title: 'Alternativa a Odoo para agencias | Vértice',
    description: 'Alternativa a Odoo para agencias: proyectos, CRM, cotizaciones y facturación listos en minutos, sin implementación. Empieza gratis.',
    support: { kanban: 'yes', gantt: 'partial', crm: 'yes', quotes: 'yes', invoicing: 'yes', profit: 'yes', clients: 'yes', network: 'partial', agencies: 'partial' },
    notes: { gantt: 'Según edición', clients: 'Portal de clientes', network: 'Como usuarios del portal', agencies: 'ERP generalista' },
    chooseThem: ['Necesitas un ERP completo: inventario, manufactura, contabilidad o RR. HH.', 'Cuentas con un equipo o partner para implementarlo y mantenerlo.'],
    chooseUs: ['Quieres empezar hoy, sin implementación ni módulos que configurar.', 'Tu negocio es una agencia y colaboras con otras agencias en los mismos proyectos.'],
    faqs: [
      { q: '¿Vértice reemplaza a Odoo?', a: 'Para una agencia, Vértice cubre lo esencial del día a día: proyectos, CRM, cotizaciones, facturación, caja y rentabilidad. Si necesitas inventario, manufactura o contabilidad completa, un ERP como Odoo puede encajar mejor.' },
      { q: '¿Necesito implementación para usar Vértice?', a: 'No. Creas tu cuenta, configuras tu marca, estados y roles, y empiezas a trabajar el mismo día.' },
      { q: '¿Cuánto cuesta empezar con Vértice?', a: 'Puedes crear tu cuenta gratis y configurar tu agencia en minutos, sin instalar nada.' },
    ],
  },
  {
    slug: 'monday',
    name: 'monday.com',
    tagline: 'Plataforma de gestión de trabajo con tableros personalizables.',
    title: 'Alternativa a monday.com para agencias | Vértice',
    description: 'Alternativa a monday.com para agencias: proyectos, Gantt, CRM, cotizaciones y facturación incluidos en un mismo producto. Empieza gratis.',
    support: { kanban: 'yes', gantt: 'partial', crm: 'partial', quotes: 'partial', invoicing: 'partial', profit: 'partial', clients: 'yes', network: 'partial', agencies: 'partial' },
    notes: { gantt: 'Según plan', crm: 'Producto aparte', quotes: 'Con el producto CRM', invoicing: 'Con el producto CRM', profit: 'Con columnas y fórmulas', clients: 'Como invitados', network: 'Como invitados', agencies: 'Generalista' },
    chooseThem: ['Quieres construir tus propios flujos con tableros muy personalizables.', 'Tu empresa ya usa varios productos de monday.com.'],
    chooseUs: ['Prefieres una plataforma lista para agencias, sin armar tableros desde cero.', 'Quieres proyectos, CRM y facturación incluidos en un mismo producto.'],
    faqs: [
      { q: '¿Vértice es una alternativa a monday.com?', a: 'Sí. Vértice está pensado para agencias e incluye proyectos, Gantt, CRM, cotizaciones, facturación y rentabilidad en un mismo producto.' },
      { q: '¿Puedo compartir proyectos con otras agencias?', a: 'Sí. Las agencias se conectan por código y comparten proyectos con acceso de Ver o Colaborar, cada una con su propia cuenta y sin exponer sus finanzas.' },
      { q: '¿Cuánto cuesta empezar con Vértice?', a: 'Puedes crear tu cuenta gratis y configurar tu agencia en minutos, sin instalar nada.' },
    ],
  },
];

export const COMPARE_UPDATED = 'noviembre de 2026';
