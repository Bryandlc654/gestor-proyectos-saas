// Las dos apps de Vértice: Agency (ERP para agencias) y ERP (para empresas).
// Vértice ERP aún no tiene registro público: sus botones llevan a "Solicitar demo".

/** Número de WhatsApp para las demos de Vértice ERP (solo dígitos, con código de país). Se define en front/.env. */
const whatsapp = String(import.meta.env.VITE_WHATSAPP_NUMBER ?? '').replace(/\D/g, '');

/** Enlace de "Solicitar demo" de Vértice ERP: WhatsApp si hay número; si no, el bloque de contacto del ERP en la landing. */
export const ERP_DEMO_HREF = whatsapp
  ? `https://wa.me/${whatsapp}?text=${encodeURIComponent('Hola, quiero una demo de Vértice ERP')}`
  : '/#erp-demo';
export const ERP_DEMO_EXTERNAL = whatsapp !== '';

export const AGENCY_SIGNUP_HREF = '/app/register';

export type ErpModule = { key: string; name: string; text: string };
export type ErpGroup = { id: string; title: string; text: string; modules: ErpModule[] };

export const ERP_GROUPS: ErpGroup[] = [
  {
    id: 'ventas',
    title: 'Ventas y facturación',
    text: 'Cotiza, factura electrónicamente ante SUNAT y cobra.',
    modules: [
      { key: 'clientes', name: 'Clientes', text: 'Ficha por cliente con RUC/DNI, contactos e historial de ventas.' },
      { key: 'cotizaciones', name: 'Cotizaciones', text: 'Propuestas en PDF que se convierten en comprobantes.' },
      { key: 'facturacion', name: 'Facturación electrónica', text: 'Facturas y boletas enviadas a SUNAT a través de Nubefact.' },
      { key: 'notas', name: 'Notas de crédito y débito', text: 'Anula, corrige o ajusta comprobantes ya emitidos.' },
      { key: 'cxc', name: 'Cuentas por cobrar', text: 'Saldos pendientes, vencimientos y abonos por cliente.' },
    ],
  },
  {
    id: 'operaciones',
    title: 'Compras e inventario',
    text: 'Controla qué compras, qué tienes y cuánto vale.',
    modules: [
      { key: 'productos', name: 'Productos', text: 'Catálogo de productos y servicios con precios y unidades.' },
      { key: 'inventario', name: 'Inventario', text: 'Stock actualizado con cada compra y cada venta.' },
      { key: 'kardex', name: 'Kardex', text: 'Entradas, salidas y saldo valorizado de cada producto.' },
      { key: 'compras', name: 'Compras', text: 'Registra compras y alimenta el inventario automáticamente.' },
      { key: 'proveedores', name: 'Proveedores', text: 'Directorio de proveedores con su historial de compras.' },
      { key: 'cxp', name: 'Cuentas por pagar', text: 'Deudas con proveedores, vencimientos y pagos.' },
    ],
  },
  {
    id: 'personas',
    title: 'Personas y control',
    text: 'Tu equipo y los números de tu empresa a la vista.',
    modules: [
      { key: 'colaboradores', name: 'Colaboradores', text: 'Datos, cargos y accesos de cada colaborador.' },
      { key: 'asistencias', name: 'Asistencias', text: 'Marcaciones de entrada y salida, tardanzas y faltas.' },
      { key: 'reportes', name: 'Reportes', text: 'Ventas, compras, inventario y cobranzas en un vistazo.' },
    ],
  },
];

export const ERP_MODULE_NAMES = ERP_GROUPS.flatMap((g) => g.modules.map((m) => m.name));
