/**
 * MessageDetail — right-pane message thread + reply composer.
 *
 * Features:
 * - Grouped bubbles (left=received, right=sent) with date dividers
 * - Real read receipts from msg.is_read (no faking)
 * - Attachment upload: paperclip → tray (images preview, files chip)
 * - Client-side validation: max 5 files, 10 MB each, allowed types
 * - Image attachments fetched via blob (Bearer auth, no public URL)
 * - File attachments download via blob trigger
 * - Linkified body text (no dangerouslySetInnerHTML)
 * - Ctrl/Cmd+Enter to send; Shift+Enter / plain Enter = newline
 */
import { useEffect, useRef, useState, useCallback } from 'react';
import {
  ArrowLeft,
  Check,
  CheckCheck,
  File,
  FileText,
  Paperclip,
  Send,
  X,
} from 'lucide-react';
import type { ConversationDetail, ConversationMessage, MessageAttachment } from '@/lib/types';
import { tenantApi } from '@/lib/endpoints';
import { dayLabel, clockTime } from './meta';
import { Avatar, type AvatarRole } from './atoms';

/* ── helpers ---------------------------------------------------------------- */

/** Tiny helper: format bytes into KB / MB / GB */
function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`;
}

/** Linkify a string into an array of React nodes (text + <a> anchors).
 *  Never uses dangerouslySetInnerHTML. URLs are matched with a safe regex. */
const URL_REGEX = /https?:\/\/[^\s<>"']+/g;

function linkify(text: string): React.ReactNode[] {
  const parts: React.ReactNode[] = [];
  let last = 0;
  let match: RegExpExecArray | null;
  // reset lastIndex since regex is module-level
  URL_REGEX.lastIndex = 0;
  while ((match = URL_REGEX.exec(text)) !== null) {
    if (match.index > last) {
      parts.push(text.slice(last, match.index));
    }
    const url = match[0];
    parts.push(
      <a
        key={match.index}
        href={url}
        target="_blank"
        rel="noopener noreferrer"
        className="mx-bubble-link"
      >
        {url}
      </a>,
    );
    last = match.index + url.length;
  }
  if (last < text.length) parts.push(text.slice(last));
  return parts;
}

/* ── Attachment client validation ------------------------------------------- */
const ALLOWED_MIME = new Set([
  'image/jpeg', 'image/png', 'image/webp', 'image/gif',
  'application/pdf',
  'application/msword',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'text/plain', 'text/csv',
  'application/vnd.ms-excel',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
]);
const ALLOWED_EXT = /\.(jpg|jpeg|png|webp|gif|pdf|doc|docx|txt|csv|xls|xlsx)$/i;
const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB
const MAX_FILES = 5;

function isAllowed(file: File): boolean {
  return (ALLOWED_MIME.has(file.type) || ALLOWED_EXT.test(file.name));
}

/* ── Image attachment card -------------------------------------------------- */
function ImageCard({ att }: { att: MessageAttachment }) {
  const [state, setState] = useState<{ url: string | null; loading: boolean }>({ url: null, loading: true });

  useEffect(() => {
    let revoke: string | null = null;
    tenantApi.messageAttachmentBlob(att.id)
      .then((u) => { revoke = u; setState({ url: u, loading: false }); })
      .catch(() => { setState({ url: null, loading: false }); });
    return () => { if (revoke) URL.revokeObjectURL(revoke); };
  }, [att.id]);

  const { url: blobUrl, loading } = state;

  if (loading) {
    return <div className="mx-att-img-skel" aria-label="Loading image…" />;
  }
  if (!blobUrl) return null;

  return (
    <a href={blobUrl} target="_blank" rel="noopener noreferrer" className="mx-att-img-wrap">
      <img
        src={blobUrl}
        alt={att.original_name}
        className="mx-att-img"
        loading="lazy"
      />
    </a>
  );
}

/* ── File attachment card --------------------------------------------------- */
function FileCard({ att }: { att: MessageAttachment }) {
  const [downloading, setDownloading] = useState(false);
  const ext = att.original_name.split('.').pop()?.toUpperCase() ?? 'FILE';

  const handleDownload = useCallback(async () => {
    if (downloading) return;
    setDownloading(true);
    try {
      const url = await tenantApi.messageAttachmentBlob(att.id);
      const a = document.createElement('a');
      a.href = url;
      a.download = att.original_name;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    } finally {
      setDownloading(false);
    }
  }, [att.id, att.original_name, downloading]);

  return (
    <div className="mx-att">
      <span className="mx-att-ico">
        <FileText size={18} />
      </span>
      <span className="mx-att-body">
        <span className="mx-att-name">{att.original_name}</span>
        <span className="mx-att-meta">{ext} · {formatBytes(att.size_bytes)}</span>
      </span>
      <button
        type="button"
        className="mx-att-act"
        onClick={() => void handleDownload()}
        disabled={downloading}
        aria-label={`Download ${att.original_name}`}
        title="Download"
      >
        {downloading ? (
          <span className="mx-att-spin" aria-hidden="true" />
        ) : (
          <File size={15} />
        )}
      </button>
    </div>
  );
}

/* ── Attachment grid (images + files) --------------------------------------- */
function AttachmentGrid({ attachments }: { attachments: MessageAttachment[] }) {
  const images = attachments.filter((a) => a.attachment_type === 'image');
  const files = attachments.filter((a) => a.attachment_type === 'file');

  return (
    <div className="mx-bubble-atts">
      {images.length > 0 && (
        <div className="mx-att-img-grid">
          {images.map((a) => <ImageCard key={a.id} att={a} />)}
        </div>
      )}
      {files.map((a) => <FileCard key={a.id} att={a} />)}
    </div>
  );
}

/* ── Conversation header ---------------------------------------------------- */
interface HeaderProps {
  conversation: ConversationDetail['conversation'];
  onBack?: () => void;
}

function ConversationHeader({ conversation, onBack }: HeaderProps) {
  const other = conversation.other_participant;
  const title = conversation.title ?? other?.name ?? 'Conversation';
  const name = other?.name ?? 'Landlord';
  const role = other?.role;
  // Prefer the participant's profile photo; fall back to the listing thumbnail.
  const avatarSrc = other?.avatar_url ?? conversation.thumbnail_url;

  return (
    <div className="mx-dt-bar">
      {onBack && (
        <button
          className="mx-iconbtn"
          onClick={onBack}
          aria-label="Back to conversations"
          title="Back"
        >
          <ArrowLeft size={18} />
        </button>
      )}
      <Avatar
        name={name}
        role="landlord"
        src={avatarSrc}
        size={40}
      />
      <div className="mx-dt-header-info">
        <span className="mx-dt-title-inline">{title}</span>
        <span className="mx-dt-subtitle">
          <span className="mx-dt-participant-name">{name}</span>
          {role && (
            <span className="mx-dt-role-badge">
              {role.charAt(0).toUpperCase() + role.slice(1)}
            </span>
          )}
        </span>
      </div>
    </div>
  );
}

/* ── Date divider ----------------------------------------------------------- */
function DateDivider({ iso }: { iso: string }) {
  const label = dayLabel(iso);
  if (!label) return null;
  return (
    <div className="mx-date-divider" aria-label={label}>
      <span className="mx-date-divider-label">{label}</span>
    </div>
  );
}

/* ── Individual message bubble ---------------------------------------------- */
interface BubbleProps {
  msg: ConversationMessage;
  isGroupHead: boolean;
  isGroupTail: boolean;
  senderName: string;
  role: AvatarRole;
}

function MessageBubble({ msg, isGroupHead, isGroupTail, senderName, role }: BubbleProps) {
  const isMe = msg.sender.is_me;
  const hasAtts = (msg.attachments?.length ?? 0) > 0;

  // Linkify the body text, preserving newlines
  const bodyLines = msg.body ? msg.body.split('\n') : [];
  const bodyNodes: React.ReactNode[] = bodyLines.map((line, i) => (
    <span key={i}>
      {linkify(line)}
      {i < bodyLines.length - 1 && <br />}
    </span>
  ));

  return (
    <div className={`mx-bubble-row${isMe ? ' mine' : ''}${isGroupHead ? ' group-head' : ''}${isGroupTail ? ' group-tail' : ''}`}>
      {/* Avatar — received only, shown at group head */}
      {!isMe && (
        <div className="mx-bubble-avatar-slot">
          {isGroupHead && <Avatar name={senderName} role={role} src={msg.sender.avatar_url} />}
        </div>
      )}

      <div className="mx-bubble-col">
        {/* Sender name — group head only */}
        {isGroupHead && !isMe && (
          <span className="mx-bubble-sender">{senderName}</span>
        )}

        <div className={`mx-bubble${isMe ? ' mx-bubble-sent' : ' mx-bubble-recv'}`}>
          {/* Body text */}
          {msg.body && (
            <div className="mx-bubble-text">
              {bodyNodes}
            </div>
          )}

          {/* Attachments */}
          {hasAtts && msg.attachments && (
            <AttachmentGrid attachments={msg.attachments} />
          )}
        </div>

        {/* Time + read indicator — at group tail */}
        {isGroupTail && (
          <div className={`mx-bubble-meta${isMe ? ' mine' : ''}`}>
            <span className="mx-bubble-time">{clockTime(msg.created_at)}</span>
            {isMe && (
              <span className="mx-bubble-read" title={msg.is_read ? `Seen` : 'Sent'}>
                {msg.is_read ? (
                  <><CheckCheck size={13} /><span className="mx-bubble-seen-label">Seen</span></>
                ) : (
                  <Check size={13} />
                )}
              </span>
            )}
          </div>
        )}
      </div>

      {/* Right spacer for received messages to keep alignment */}
      {!isMe && <div className="mx-bubble-spacer" />}
    </div>
  );
}

/* ── Selected attachment tray ----------------------------------------------- */
interface TrayProps {
  files: File[];
  onRemove: (index: number) => void;
}

function AttachmentTray({ files, onRemove }: TrayProps) {
  if (files.length === 0) return null;

  return (
    <div className="mx-att-tray">
      {files.map((file, i) => {
        const isImage = file.type.startsWith('image/');
        const previewUrl = isImage ? URL.createObjectURL(file) : null;

        return (
          <div key={i} className="mx-att-tray-chip">
            {isImage && previewUrl ? (
              <img
                src={previewUrl}
                alt={file.name}
                className="mx-att-tray-thumb"
                onLoad={() => previewUrl && URL.revokeObjectURL(previewUrl)}
              />
            ) : (
              <span className="mx-att-tray-ico"><File size={14} /></span>
            )}
            <span className="mx-att-tray-name" title={file.name}>{file.name}</span>
            <span className="mx-att-tray-size">{formatBytes(file.size)}</span>
            <button
              type="button"
              className="mx-att-tray-remove"
              onClick={() => onRemove(i)}
              aria-label={`Remove ${file.name}`}
            >
              <X size={12} />
            </button>
          </div>
        );
      })}
    </div>
  );
}

/* ── Loading skeleton ------------------------------------------------------- */
function DetailSkeleton() {
  return (
    <div className="mx-dt-scroll">
      {[1, 2].map((n) => (
        <div key={n} className="mx-bubble-row" style={{ marginBottom: 20 }}>
          <div className="mx-skel" style={{ width: 36, height: 36, borderRadius: '50%', flexShrink: 0 }} />
          <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 8 }}>
            <div className="mx-skel mx-skel-line" style={{ width: '22%' }} />
            <div className="mx-skel" style={{ width: '58%', height: 52, borderRadius: 12 }} />
          </div>
        </div>
      ))}
    </div>
  );
}

/* ── Placeholder when no conversation selected ------------------------------ */
export function DetailPlaceholder() {
  return (
    <section className="mx-col mx-detail" aria-label="No conversation selected">
      <div className="mx-empty" style={{ flex: 1 }}>
        <p className="mx-empty-title">Select a conversation</p>
        <p className="mx-empty-text">Choose a conversation from the list to read and reply.</p>
      </div>
    </section>
  );
}

/* ── Group messages by sender + <5 min window ------------------------------ */
interface MessageGroup {
  messages: ConversationMessage[];
  isMe: boolean;
  senderName: string;
  role: AvatarRole;
}

function groupMessages(messages: ConversationMessage[]): MessageGroup[] {
  const groups: MessageGroup[] = [];
  for (const msg of messages) {
    const isMe = msg.sender.is_me;
    const name = msg.sender.name ?? (isMe ? 'You' : 'Landlord');
    const last = groups[groups.length - 1];
    if (
      last &&
      last.isMe === isMe &&
      Math.abs(
        new Date(msg.created_at).getTime() -
        new Date(last.messages[last.messages.length - 1].created_at).getTime(),
      ) < 5 * 60 * 1000
    ) {
      last.messages.push(msg);
    } else {
      const role: AvatarRole = isMe ? 'me' : 'landlord';
      groups.push({ messages: [msg], isMe, senderName: name, role });
    }
  }
  return groups;
}

/** Returns true if the two ISO strings fall on different calendar days */
function differentDay(a: string, b: string): boolean {
  return new Date(a).toDateString() !== new Date(b).toDateString();
}

/* ── Main detail view ------------------------------------------------------- */
interface DetailProps {
  detail: ConversationDetail;
  messages: ConversationMessage[];
  loading: boolean;
  sending: boolean;
  draft: string;
  /** Caller's display name (reserved for future use; sender name comes from API). */
  meName?: string;
  onBack?: () => void;
  onDraftChange: (v: string) => void;
  onSend: (body: string, files: File[]) => void;
}

export function MessageDetailView({
  detail, messages, loading, sending, draft, meName: _meName, onBack, onDraftChange, onSend,
}: DetailProps) {
  const taRef = useRef<HTMLTextAreaElement>(null);
  const bottomRef = useRef<HTMLDivElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const conversation = detail.conversation;

  const [selectedFiles, setSelectedFiles] = useState<File[]>([]);
  const [fileError, setFileError] = useState<string | null>(null);

  /* auto-grow textarea */
  useEffect(() => {
    const ta = taRef.current;
    if (!ta) return;
    ta.style.height = 'auto';
    ta.style.height = `${Math.min(ta.scrollHeight, 160)}px`;
  }, [draft]);

  /* scroll to bottom when messages change */
  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);

  const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
      e.preventDefault();
      const canSend = (draft.trim() || selectedFiles.length > 0) && !sending;
      if (canSend) handleSend();
    }
  };

  const handleSend = () => {
    const body = draft.trim();
    const files = selectedFiles;
    if (!body && files.length === 0) return;
    onSend(body, files);
    setSelectedFiles([]);
    setFileError(null);
  };

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const incoming = Array.from(e.target.files ?? []);
    e.target.value = '';
    setFileError(null);

    const errors: string[] = [];
    const valid: File[] = [];

    for (const f of incoming) {
      if (!isAllowed(f)) {
        errors.push(`"${f.name}" is not an allowed file type.`);
        continue;
      }
      if (f.size > MAX_FILE_SIZE) {
        errors.push(`"${f.name}" exceeds 10 MB.`);
        continue;
      }
      valid.push(f);
    }

    const combined = [...selectedFiles, ...valid];
    if (combined.length > MAX_FILES) {
      errors.push(`You can attach at most ${MAX_FILES} files at once.`);
      valid.splice(MAX_FILES - selectedFiles.length);
    }

    if (errors.length > 0) setFileError(errors[0]);
    if (valid.length > 0) setSelectedFiles((prev) => [...prev, ...valid].slice(0, MAX_FILES));
  };

  const removeFile = (index: number) => {
    setSelectedFiles((prev) => prev.filter((_, i) => i !== index));
    setFileError(null);
  };

  /* Build grouped message structure with day dividers */
  const groups = groupMessages(messages);
  const canSend = (draft.trim().length > 0 || selectedFiles.length > 0) && !sending;

  /* Flat render list: interleave date dividers between groups */
  type RenderItem =
    | { type: 'divider'; iso: string; key: string }
    | { type: 'group'; group: MessageGroup; key: string };

  const renderItems: RenderItem[] = [];
  let prevMsgIso: string | null = null;

  for (const group of groups) {
    const firstIso = group.messages[0].created_at;
    if (prevMsgIso === null || differentDay(prevMsgIso, firstIso)) {
      renderItems.push({ type: 'divider', iso: firstIso, key: `div-${firstIso}` });
    }
    renderItems.push({ type: 'group', group, key: `grp-${group.messages[0].id}` });
    prevMsgIso = group.messages[group.messages.length - 1].created_at;
  }

  return (
    <section className="mx-col mx-detail" aria-label={`Conversation: ${conversation.title ?? ''}`}>
      {/* header */}
      <ConversationHeader conversation={conversation} onBack={onBack} />

      {loading ? (
        <DetailSkeleton />
      ) : (
        <>
          <div className="mx-dt-scroll">
            {messages.length === 0 ? (
              <div className="mx-empty">
                <p className="mx-empty-title">No messages yet</p>
                <p className="mx-empty-text">Send the first message below.</p>
              </div>
            ) : (
              renderItems.map((item) => {
                if (item.type === 'divider') {
                  return <DateDivider key={item.key} iso={item.iso} />;
                }
                const { group } = item;
                return (
                  <div key={item.key} className="mx-group">
                    {group.messages.map((msg, idx) => (
                      <MessageBubble
                        key={msg.id}
                        msg={msg}
                        isGroupHead={idx === 0}
                        isGroupTail={idx === group.messages.length - 1}
                        senderName={group.senderName}
                        role={group.role}
                      />
                    ))}
                  </div>
                );
              })
            )}
            <div ref={bottomRef} />
          </div>

          {/* composer */}
          <div className="mx-reply">
            {selectedFiles.length > 0 && (
              <AttachmentTray files={selectedFiles} onRemove={removeFile} />
            )}
            {fileError && (
              <p className="mx-att-error" role="alert">{fileError}</p>
            )}
            <div className="mx-comp-box">
              {/* hidden file input */}
              <input
                ref={fileInputRef}
                type="file"
                multiple
                accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.doc,.docx,.txt,.csv,.xls,.xlsx,image/*,application/pdf"
                style={{ display: 'none' }}
                onChange={handleFileChange}
              />
              <button
                type="button"
                className="mx-iconbtn mx-comp-attach"
                onClick={() => fileInputRef.current?.click()}
                aria-label="Attach files"
                title="Attach files"
                disabled={sending || selectedFiles.length >= MAX_FILES}
              >
                <Paperclip size={17} />
              </button>
              <textarea
                ref={taRef}
                rows={2}
                className="mx-comp-input"
                placeholder="Type a message… (Ctrl/⌘+Enter to send)"
                value={draft}
                onChange={(e) => onDraftChange(e.target.value)}
                onKeyDown={handleKeyDown}
                aria-label="Reply"
                disabled={sending}
              />
              <button
                className="mx-send-main solo"
                onClick={handleSend}
                disabled={!canSend}
                aria-label="Send message"
              >
                <Send size={15} />
                {sending ? 'Sending…' : 'Send'}
              </button>
            </div>
          </div>
        </>
      )}
    </section>
  );
}
