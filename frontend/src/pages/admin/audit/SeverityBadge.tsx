/**
 * SeverityBadge — maps audit severity to SemanticBadge roles.
 *
 * critical → danger (oxblood)  — most severe, needs action
 * warning  → warning (clay)    — review recommended
 * info     → info (blue)       — routine information
 *
 * Uses SemanticBadge so the color contract is consistent with the card system.
 */
import { SemanticBadge } from '@/components/cards';
import type { SemanticRole } from '@/components/cards/variants';
import type { AuditLog } from '@/lib/types';

function severityRole(severity: AuditLog['severity']): SemanticRole {
  switch (severity) {
    case 'critical': return 'danger';
    case 'warning':  return 'warning';
    default:         return 'info';
  }
}

export function SeverityBadge({ severity }: { severity: AuditLog['severity'] }) {
  const role = severityRole(severity);
  return (
    <SemanticBadge role={role} dot={severity !== 'info'}>
      {severity.charAt(0).toUpperCase() + severity.slice(1)}
    </SemanticBadge>
  );
}
