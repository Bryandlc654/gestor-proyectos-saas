// Entrada SSR usada solo en el build para prerenderizar la landing, las comparativas y las páginas de solución (ver scripts/prerender.mjs).
import { renderToString } from 'react-dom/server';
import { ComparePage } from './features/landing/ComparePage';
import { COMPETITORS } from './features/landing/compare-data';
import { LANDING_DESCRIPTION, LANDING_FAQS, LANDING_TITLE, LandingPage } from './features/landing/LandingPage';
import { SolutionPage } from './features/landing/SolutionPage';
import { SOLUTIONS } from './features/landing/solution-data';

export function render(): string {
  return renderToString(<LandingPage />);
}

export function renderCompare(slug: string): string {
  const competitor = COMPETITORS.find((c) => c.slug === slug);
  if (!competitor) throw new Error(`Comparativa desconocida: ${slug}`);
  return renderToString(<ComparePage competitor={competitor} />);
}

export function renderSolution(slug: string): string {
  const solution = SOLUTIONS.find((s) => s.slug === slug);
  if (!solution) throw new Error(`Solución desconocida: ${slug}`);
  return renderToString(<SolutionPage solution={solution} />);
}

export const faqs = LANDING_FAQS;
export const landingTitle = LANDING_TITLE;
export const landingDescription = LANDING_DESCRIPTION;
export { ERP_MODULE_NAMES as erpModules } from './features/landing/products';
export const competitors = COMPETITORS;
export const solutions = SOLUTIONS;
