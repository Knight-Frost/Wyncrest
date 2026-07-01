/**
 * ConversationList — the left-pane list of conversations.
 * Data comes from tenantApi.conversations() → ConversationSummary[].
 * No mock imports, no folders, no stars — just real data.
 */
import { Inbox, MessageSquareText } from 'lucide-react';
import type { ConversationSummary } from '@/lib/types';
import { brand } from '@/config/brand';
import { relTime } from './meta';
import { initials } from './meta';
import { Avatar } from './atoms';

interface ListProps {
  items: ConversationSummary[];
  loading: boolean;
  selectedId: number | null;
  onSelect: (id: number) => void;
  onCompose?: () => void;
}

function SkeletonRows() {
  return (
    <div className="mx-list-body" aria-hidden="true">
      {Array.from({ length: 6 }).map((_, i) => (
        <div className="mx-skel-row" key={i} style={{ gridTemplateColumns: '36px minmax(0,1fr) 52px' }}>
          <div className="mx-skel" style={{ width: 36, height: 36, borderRadius: '50%' }} />
          <div style={{ display: 'flex', flexDirection: 'column', gap: 7 }}>
            <div className="mx-skel mx-skel-line" style={{ width: '55%' }} />
            <div className="mx-skel mx-skel-line" style={{ width: '90%' }} />
          </div>
          <div className="mx-skel mx-skel-line" style={{ width: 44 }} />
        </div>
      ))}
    </div>
  );
}

function Row({
  item, selected, onSelect,
}: {
  item: ConversationSummary; selected: boolean; onSelect: (id: number) => void;
}) {
  const unread = item.unread_count > 0;
  const participantName = item.other_participant?.name ?? brand.appName;
  const participantInitials = item.other_participant?.initials ?? initials(participantName);
  const preview = item.last_message_preview ?? item.preview ?? '';
  const timeStr = item.last_message_at ? relTime(item.last_message_at) : '';
  const title = item.title ?? participantName;
  // Prefer the other person's profile photo; fall back to the listing thumbnail.
  const avatarSrc = item.other_participant?.avatar_url ?? item.thumbnail_url;
  const rawRole = item.other_participant?.role ?? null;
  const avatarRole: 'landlord' | 'tenant' | 'admin' | 'system' =
    rawRole === 'tenant' || rawRole === 'admin' || rawRole === 'system' ? rawRole : 'landlord';

  return (
    <div
      role="button"
      tabIndex={0}
      className={`mx-conv-row${unread ? ' unread' : ''}${selected ? ' selected' : ''}`}
      onClick={() => onSelect(item.id)}
      onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onSelect(item.id); } }}
      aria-pressed={selected}
      aria-label={`Conversation with ${participantName}: ${title}`}
    >
      <span className="mx-conv-avatar-wrap">
        {avatarSrc ? (
          <Avatar name={participantName} role={avatarRole} src={avatarSrc} size={44} />
        ) : (
          <span className="mx-conv-avatar">{participantInitials}</span>
        )}
        {unread && <span className="mx-conv-dot" aria-label="Unread" />}
      </span>

      <span className="mx-conv-body">
        <span className="mx-conv-name">{participantName}</span>
        <span className="mx-conv-title">{title}</span>
        {preview && <span className="mx-conv-preview">{preview}</span>}
      </span>

      <span className="mx-conv-meta">
        <span className="mx-conv-time">{timeStr}</span>
        {unread && item.unread_count > 1 && (
          <span className="mx-conv-badge">{item.unread_count}</span>
        )}
      </span>
    </div>
  );
}

export function ConversationList({ items, loading, selectedId, onSelect, onCompose }: ListProps) {
  if (loading) return <SkeletonRows />;

  if (items.length === 0) {
    return (
      <div className="mx-empty" style={{ flex: 1 }}>
        <span className="mx-empty-ico"><MessageSquareText size={24} /></span>
        <p className="mx-empty-title">No messages yet</p>
        <p className="mx-empty-text">Start a conversation with a landlord about a home you've saved.</p>
        {onCompose && (
          <button type="button" className="mx-btn mx-btn-primary" onClick={onCompose}>
            New message
          </button>
        )}
      </div>
    );
  }

  return (
    <div className="mx-list-body" role="listbox" aria-label="Conversations">
      {items.map((item) => (
        <Row
          key={item.id}
          item={item}
          selected={selectedId === item.id}
          onSelect={onSelect}
        />
      ))}
    </div>
  );
}

export { Inbox };
