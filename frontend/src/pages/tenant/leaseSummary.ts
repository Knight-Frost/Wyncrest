/**
 * Client-side "download lease" for the tenant Lease & Rent pages.
 *
 * There is no stored, landlord-signed lease PDF in the system — this builds a
 * plain-text summary from the real, already-loaded contract data instead of
 * pretending to produce a legal document.
 */
import { formatCents, formatDate, formatDollars, humanize } from '@/lib/format';
import type { Contract } from '@/lib/types';

function triggerDownload(filename: string, text: string) {
  const blob = new Blob([text], { type: 'text/plain' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}

function partyLine(label: string, name: string, email?: string | null): string {
  return `${label}: ${name}${email ? ` (${email})` : ''}`;
}

export function downloadLeaseSummary(contract: Contract, tenantName: string, tenantEmail: string) {
  const unit = contract.listing?.unit;
  const property = unit?.property;
  const home = property
    ? `${property.name}${unit?.unit_number ? `, Unit ${unit.unit_number}` : ''}`
    : (contract.listing?.title ?? `Contract ${contract.id.slice(0, 8)}…`);
  const address = property
    ? `${property.street_address}, ${property.city}, ${property.state} ${property.zip_code}`
    : null;
  const landlordName = contract.landlord ? contract.landlord.full_name : `Landlord #${contract.landlord_id}`;

  const lines = [
    'WYNCREST LEASE SUMMARY',
    `Contract ${contract.id}`,
    '',
    home,
    ...(address ? [address] : []),
    '',
    partyLine('Tenant', tenantName, tenantEmail),
    partyLine('Landlord', landlordName, contract.landlord?.email),
    '',
    `Status: ${humanize(contract.status)}`,
    `Term: ${formatDate(contract.start_date)} to ${formatDate(contract.end_date)}`,
    `Rent: ${formatCents(contract.rent_amount)} per month (${humanize(contract.billing_cycle)}), due day ${contract.payment_day} of each cycle`,
    ...(unit?.security_deposit ? [`Security deposit: ${formatDollars(unit.security_deposit)}`] : []),
    ...(contract.termination_reason ? ['', `Termination reason: ${contract.termination_reason}`] : []),
    '',
    'This is a summary of your lease details generated from your Wyncrest account.',
    'It is not a substitute for the signed lease agreement.',
  ];

  triggerDownload(`wyncrest-lease-${contract.id.slice(0, 8)}.txt`, lines.join('\n'));
}

export function downloadTerminationNotice(contract: Contract, tenantName: string) {
  const home = contract.listing?.unit?.property?.name ?? contract.listing?.title ?? `Contract ${contract.id.slice(0, 8)}…`;
  const lines = [
    'WYNCREST TERMINATION NOTICE',
    `Contract ${contract.id}`,
    '',
    home,
    '',
    `Terminated by: ${contract.terminated_by ? humanize(contract.terminated_by) : 'Unknown'}`,
    `Reason: ${contract.termination_reason ?? 'Not recorded'}`,
    '',
    `Tenant of record: ${tenantName}`,
  ];

  triggerDownload(`wyncrest-termination-${contract.id.slice(0, 8)}.txt`, lines.join('\n'));
}
