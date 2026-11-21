// Genera HTML prerenderizado con SEO completo:
//   dist/welcome.html            home de Vértice ERP (index.php la sirve en "/")
//   dist/agencias.html           Vértice Agency (software-para-agencias.php la sirve en "/software-para-agencias")
//   dist/compare/<slug>.html     comparativas (compare.php, "/comparar/<slug>")
//   dist/soluciones/<slug>.html  soluciones (solution.php, "/soluciones/<slug>")
import { mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const site = (process.env.SITE_URL || 'https://verticeplatform.com').replace(/\/$/, '');
const agencyPath = '/software-para-agencias';
const ssrDir = resolve(root, 'dist-ssr');

const { render, renderAgency, renderCompare, renderSolution, faqs, agencyFaqs, agencyTitle, agencyDescription, competitors, solutions, landingTitle, landingDescription, erpModules } = await import(pathToFileURL(resolve(ssrDir, 'landing-ssr.js')).href);
const appHtml = render();

// La home es solo de Vértice ERP; Vértice Agency tiene su página en /software-para-agencias.
// Título y description viven en LandingPage.tsx; la description se mantiene por debajo de ~155 caracteres.
const title = landingTitle;
const description = landingDescription;
const ogImage = `${site}/assets/vertice-og.png`;

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const json = (o) => JSON.stringify(o).replace(/</g, '\\u003c');

const structuredData = [
  {
    '@context': 'https://schema.org',
    '@type': 'Organization',
    name: 'Vértice',
    alternateName: 'Vértice Platform',
    areaServed: { '@type': 'Country', name: 'Perú' },
    url: `${site}/`,
    logo: `${site}/assets/logo.png`,
    description: 'Vértice desarrolla Vértice ERP, el ERP para empresas con facturación electrónica SUNAT, y Vértice Agency, el sistema para agencias que trabajan por proyectos.',
  },
  {
    '@context': 'https://schema.org',
    '@type': 'WebSite',
    name: 'Vértice',
    url: `${site}/`,
    inLanguage: 'es',
  },
  {
    '@context': 'https://schema.org',
    '@type': 'SoftwareApplication',
    name: 'Vértice ERP',
    applicationCategory: 'BusinessApplication',
    applicationSubCategory: 'ERP',
    operatingSystem: 'Web',
    url: `${site}/`,
    image: ogImage,
    description: 'Sistema ERP en la nube para empresas en Perú: facturación electrónica SUNAT (vía Nubefact), inventario, kardex, compras, cuentas por cobrar y por pagar, colaboradores y asistencias.',
    inLanguage: 'es',
    featureList: erpModules,
  },
  {
    '@context': 'https://schema.org',
    '@type': 'ItemList',
    name: 'Soluciones de Vértice ERP',
    itemListElement: solutions.filter((s) => s.product === 'erp').map((s, i) => ({ '@type': 'ListItem', position: i + 1, name: s.name, url: `${site}/soluciones/${s.slug}` })),
  },
  {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: faqs.map((f) => ({ '@type': 'Question', name: f.q, acceptedAnswer: { '@type': 'Answer', text: f.a } })),
  },
];

function seoHead({ path, pageTitle, pageDescription, data, extra = '' }) {
  const url = `${site}${path}`;
  return `
    <title>${esc(pageTitle)}</title>
    <meta name="description" content="${esc(pageDescription)}" />
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1" />
    <link rel="canonical" href="${url}" />
    <link rel="alternate" hreflang="es" href="${url}" />
    <link rel="alternate" hreflang="x-default" href="${url}" />
    <meta property="og:type" content="website" />
    <meta property="og:locale" content="es_PE" />
    <meta property="og:site_name" content="Vértice" />
    <meta property="og:title" content="${esc(pageTitle)}" />
    <meta property="og:description" content="${esc(pageDescription)}" />
    <meta property="og:url" content="${url}" />
    <meta property="og:image" content="${ogImage}" />
    <meta property="og:image:width" content="1200" />
    <meta property="og:image:height" content="630" />
    <meta property="og:image:alt" content="${esc(pageTitle)}" />
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" content="${esc(pageTitle)}" />
    <meta name="twitter:description" content="${esc(pageDescription)}" />
    <meta name="twitter:image" content="${ogImage}" />
    <link rel="manifest" href="/manifest.webmanifest" />
    <script type="application/ld+json">${json(data)}</script>
    ${extra}`;
}

const distIndex = resolve(root, 'dist/index.html');
const shell = readFileSync(distIndex, 'utf8');
const marker = /<!--seo:start-->[\s\S]*?<!--seo:end-->/;
if (!marker.test(shell)) throw new Error('index.html no tiene los marcadores <!--seo:start--> / <!--seo:end-->');
if (!shell.includes('<div id="root" class="h-full"></div>')) throw new Error('index.html no tiene el contenedor #root esperado');

// El CSS y JS de la landing son chunks dinámicos: se enlazan en el <head> para evitar un flash sin estilos.
const base = (shell.match(/src="([^"]*)assets\/index-[^"]+\.js"/) || [, '/front/dist/'])[1];
const assets = readdirSync(resolve(root, 'dist/assets'));
const landingCss = assets.filter((f) => /^PublicSite-.*\.css$/.test(f)).map((f) => `<link rel="stylesheet" href="${base}assets/${f}" />`).join('\n    ');
const landingJs = assets.filter((f) => /^PublicSite-.*\.js$/.test(f)).map((f) => `<link rel="modulepreload" href="${base}assets/${f}" />`).join('\n    ');
if (!landingCss) throw new Error('No se encontró el CSS de la landing en dist/assets');

const build = (head, body, { withScripts }) => {
  let html = shell
    .replace(marker, head.trim())
    .replace('<div id="root" class="h-full"></div>', `<div id="root" class="h-full">${body}</div>`);
  // Las comparativas son HTML estático: sin el JS de la app (que montaría el router en esa ruta).
  if (!withScripts) html = html.replace(/\s*<script type="module"[^>]*><\/script>/g, '');
  return html;
};

// Landing
const landingHtml = build(
  `${seoHead({ path: '/', pageTitle: title, pageDescription: description, data: structuredData, extra: '<noscript><style>.vx-reveal{opacity:1!important;transform:none!important;filter:none!important}</style></noscript>' })}\n    ${landingCss}\n    ${landingJs}`,
  appHtml,
  { withScripts: true },
);
writeFileSync(resolve(root, 'dist/welcome.html'), landingHtml);
console.log(`✓ dist/welcome.html prerenderizado (${(landingHtml.length / 1024).toFixed(1)} kB) para ${site}`);

// Vértice Agency: página propia en /software-para-agencias
{
  const data = [
  {
    '@context': 'https://schema.org',
    '@type': 'SoftwareApplication',
    name: 'Vértice Agency',
    applicationCategory: 'BusinessApplication',
    applicationSubCategory: 'Gestión de proyectos',
    operatingSystem: 'Web',
    url: `${site}${agencyPath}`,
    image: ogImage,
    description: 'Software de gestión para agencias: gestión de proyectos con Kanban y Gantt, CRM, cotizaciones, facturación y rentabilidad, en red con agencias aliadas y clientes.',
    inLanguage: 'es',
    offers: { '@type': 'Offer', price: '0', priceCurrency: 'USD' },
    featureList: [
      'Gestión de proyectos con Kanban y Gantt', 'Tareas, subtareas y dependencias', 'CRM con pipeline comercial',
      'Cotizaciones en PDF', 'Facturación con pagos parciales', 'Caja y cuentas por pagar', 'Rentabilidad por proyecto',
      'Conexión entre agencias por código', 'Proyectos compartidos entre agencias con acceso de ver o colaborar', 'Portal de clientes con acceso limitado', 'Tickets de soporte', 'Roles y permisos por módulo', 'API REST y webhooks',
    ],
  },
  {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { '@type': 'ListItem', position: 1, name: 'Vértice', item: `${site}/` },
      { '@type': 'ListItem', position: 2, name: 'Vértice Agency', item: `${site}${agencyPath}` },
    ],
  },
  {
    '@context': 'https://schema.org',
    '@type': 'ItemList',
    name: 'Soluciones de Vértice Agency',
    itemListElement: solutions.filter((s) => s.product !== 'erp').map((s, i) => ({ '@type': 'ListItem', position: i + 1, name: s.name, url: `${site}/soluciones/${s.slug}` })),
  },
  {
    '@context': 'https://schema.org',
    '@type': 'ItemList',
    name: 'Comparativas de Vértice Agency',
    itemListElement: competitors.map((c, i) => ({ '@type': 'ListItem', position: i + 1, name: `Vértice Agency vs ${c.name}`, url: `${site}/comparar/${c.slug}` })),
  },
  {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: agencyFaqs.map((f) => ({ '@type': 'Question', name: f.q, acceptedAnswer: { '@type': 'Answer', text: f.a } })),
  },
  ];
  const html = build(
    `${seoHead({ path: agencyPath, pageTitle: agencyTitle, pageDescription: agencyDescription, data, extra: '<noscript><style>.vx-reveal{opacity:1!important;transform:none!important;filter:none!important}</style></noscript>' })}
    ${landingCss}
    ${landingJs}`,
    renderAgency(),
    { withScripts: true },
  );
  writeFileSync(resolve(root, 'dist/agencias.html'), html);
  console.log(`✓ dist/agencias.html (${(html.length / 1024).toFixed(1)} kB)`);
}

// Comparativas
mkdirSync(resolve(root, 'dist/compare'), { recursive: true });
for (const c of competitors) {
  const path = `/comparar/${c.slug}`;
  const data = [
    {
      '@context': 'https://schema.org',
      '@type': 'WebPage',
      name: c.title,
      description: c.description,
      url: `${site}${path}`,
      inLanguage: 'es',
      about: [
        { '@type': 'SoftwareApplication', name: 'Vértice Agency', applicationCategory: 'BusinessApplication', operatingSystem: 'Web', url: `${site}${agencyPath}` },
        { '@type': 'SoftwareApplication', name: c.name, applicationCategory: 'BusinessApplication' },
      ],
    },
    {
      '@context': 'https://schema.org',
      '@type': 'BreadcrumbList',
      itemListElement: [
        { '@type': 'ListItem', position: 1, name: 'Vértice', item: `${site}/` },
        { '@type': 'ListItem', position: 2, name: 'Vértice Agency', item: `${site}${agencyPath}` },
        { '@type': 'ListItem', position: 3, name: `Vértice vs ${c.name}`, item: `${site}${path}` },
      ],
    },
    {
      '@context': 'https://schema.org',
      '@type': 'FAQPage',
      mainEntity: c.faqs.map((f) => ({ '@type': 'Question', name: f.q, acceptedAnswer: { '@type': 'Answer', text: f.a } })),
    },
  ];
  const html = build(`${seoHead({ path, pageTitle: c.title, pageDescription: c.description, data })}\n    ${landingCss}`, renderCompare(c.slug), { withScripts: false });
  writeFileSync(resolve(root, `dist/compare/${c.slug}.html`), html);
  console.log(`✓ dist/compare/${c.slug}.html (${(html.length / 1024).toFixed(1)} kB)`);
}

// Soluciones (funciones y sectores): /soluciones/<slug>
rmSync(resolve(root, 'dist/soluciones'), { recursive: true, force: true });
mkdirSync(resolve(root, 'dist/soluciones'), { recursive: true });
for (const s of solutions) {
  const path = `/soluciones/${s.slug}`;
  const pageName = `${s.h1Lead} ${s.h1Highlight}`;
  const isErp = s.product === 'erp';
  const app = isErp
    ? { '@type': 'SoftwareApplication', name: 'Vértice ERP', applicationCategory: 'BusinessApplication', applicationSubCategory: 'ERP', operatingSystem: 'Web', url: `${site}/` }
    : { '@type': 'SoftwareApplication', name: 'Vértice Agency', applicationCategory: 'BusinessApplication', operatingSystem: 'Web', url: `${site}${agencyPath}`, offers: { '@type': 'Offer', price: '0', priceCurrency: 'USD' } };
  const data = [
    {
      '@context': 'https://schema.org',
      '@type': 'WebPage',
      name: s.title,
      headline: pageName,
      description: s.description,
      url: `${site}${path}`,
      inLanguage: 'es',
      isPartOf: { '@type': 'WebSite', name: 'Vértice', url: `${site}/` },
      about: app,
    },
    {
      '@context': 'https://schema.org',
      '@type': 'BreadcrumbList',
      itemListElement: [
        { '@type': 'ListItem', position: 1, name: 'Vértice', item: `${site}/` },
        { '@type': 'ListItem', position: 2, name: app.name, item: app.url },
        { '@type': 'ListItem', position: 3, name: s.name, item: `${site}${path}` },
      ],
    },
    {
      '@context': 'https://schema.org',
      '@type': 'FAQPage',
      mainEntity: s.faqs.map((f) => ({ '@type': 'Question', name: f.q, acceptedAnswer: { '@type': 'Answer', text: f.a } })),
    },
  ];
  const html = build(`${seoHead({ path, pageTitle: s.title, pageDescription: s.description, data })}\n    ${landingCss}`, renderSolution(s.slug), { withScripts: false });
  writeFileSync(resolve(root, `dist/soluciones/${s.slug}.html`), html);
  console.log(`✓ dist/soluciones/${s.slug}.html (${(html.length / 1024).toFixed(1)} kB)`);
}

rmSync(ssrDir, { recursive: true, force: true });
