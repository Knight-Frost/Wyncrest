/**
 * Sort key for the "Needs Attention" triage board on the admin Maintenance
 * oversight page. Lower = surfaces first.
 *
 * Weighting order (highest to lowest precedence):
 *   1. Life/safety dominates — c.has_severe_safety_flag (water leak, no
 *      power, security, injury risk).
 *   2. Escalation — c.escalated_at (an admin explicitly flagged this).
 *   3. The landlord/tenant-set c.priority ('urgent' > 'high' > 'medium' > 'low').
 *   4. Overdue — c.is_overdue (past the landlord's own expected_completion_date).
 *   5. Unassigned — !c.handling_admin (no admin owns this case yet).
 *   6. Age — c.age_days, as a tiebreaker (older surfaces first).
 *
 * Do NOT invent an SLA/response-time target here — that concept doesn't
 * exist on the backend (see MaintenanceOverviewService's docblock). Rank
 * using only the fields above.
 */
import type { AdminMaintenanceCase } from '@/lib/types';

export function triagePriority(c: AdminMaintenanceCase): number {
  let score = 0;
  if (c.has_severe_safety_flag) score -= 10000; // life/safety dominates
  if (c.escalated_at) score -= 5000; // an admin explicitly flagged it
  const p = c.priority; // landlord/tenant-set priority
  score -= p === 'urgent' ? 4000 : p === 'high' ? 3000 : p === 'medium' ? 1500 : p === 'low' ? 500 : 0;
  if (c.is_overdue) score -= 2000; // past expected completion
  if (!c.handling_admin) score -= 1000; // unassigned needs an owner
  score -= c.age_days; // older surfaces first (tiebreaker)
  return score;
}
