import type { ChecklistResult, DocumentType, VerificationRequestStatus } from '@/lib/types';

export function verificationStatusLabel(status: VerificationRequestStatus): string {
  switch (status) {
    case 'pending':
      return 'Pending';
    case 'under_review':
      return 'Under review';
    case 'approved':
      return 'Verified';
    case 'rejected':
      return 'Rejected';
    case 'needs_more_information':
      return 'Needs info';
    default:
      return status;
  }
}

export function documentTypeLabel(type: DocumentType | string): string {
  switch (type) {
    case 'identity_document':
      return 'Identity Document';
    case 'proof_of_address':
      return 'Proof of Address';
    case 'proof_of_income':
      return 'Proof of Income';
    case 'lease_document':
      return 'Lease Document';
    case 'application_attachment':
      return 'Application Attachment';
    case 'maintenance_attachment':
      return 'Maintenance Attachment';
    case 'other':
      return 'Other';
    default:
      return type;
  }
}

export function checklistResultLabel(result: ChecklistResult): string {
  switch (result) {
    case 'passed':
      return 'Passed';
    case 'warning':
      return 'Warning';
    case 'failed':
      return 'Failed';
    case 'manual':
      return 'Manual check required';
    case 'not_applicable':
      return 'Not applicable';
    default:
      return result;
  }
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)} KB`;
  }
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/** Mime types the in-browser DocumentViewer can render inline. */
export function isPreviewableMime(mime: string): boolean {
  return [
    'application/pdf',
    'image/png',
    'image/jpeg',
    'image/jpg',
    'image/webp',
    'image/gif',
  ].includes(mime);
}
