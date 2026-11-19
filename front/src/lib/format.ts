const SYMBOLS: Record<string, string> = {
  USD: '$',
  EUR: '€',
  MXN: '$',
  PEN: 'S/',
  COP: '$',
  CLP: '$',
  ARS: '$',
};

export function formatMoney(amount: number, currency = 'USD'): string {
  const symbol = SYMBOLS[currency] ?? '';
  const value = amount.toLocaleString('es-ES', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
  return `${currency} ${symbol}${value}`;
}

export function formatTotals(totals: Record<string, number>, max = 2): string {
  const entries = Object.entries(totals)
    .filter(([, amount]) => Math.abs(amount) >= 0.005)
    .sort((a, b) => Math.abs(b[1]) - Math.abs(a[1]));

  if (entries.length === 0) return '0.00';

  const shown = entries.slice(0, max).map(([currency, amount]) => formatMoney(amount, currency));
  const rest = entries.length - max;
  if (rest > 0) shown.push(`+${rest} más`);
  return shown.join(' · ');
}

export function formatDate(value: string | null, options?: Intl.DateTimeFormatOptions): string {
  if (!value) return 'Sin fecha';
  const date = new Date(`${value}T00:00:00`);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleDateString('es-ES', options ?? { day: '2-digit', month: 'short', year: 'numeric' });
}

export function initials(name: string, length = 2): string {
  return name.trim().split(/\s+/).map((part) => part[0] ?? '').join('').slice(0, length).toUpperCase();
}

export function isOverdue(value: string | null): boolean {
  if (!value) return false;
  const date = new Date(`${value}T23:59:59`);
  return date.getTime() < Date.now();
}
