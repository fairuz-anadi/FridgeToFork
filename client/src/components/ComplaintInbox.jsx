import { useCallback, useEffect, useState } from "react";
import { api } from "../api/api";
import { categoryLabel } from "./contactCategories";
import { useToast } from "./useToast";

function formatDate(value) {
  return value
    ? new Intl.DateTimeFormat("en-US", { month: "short", day: "numeric", year: "numeric" }).format(new Date(value))
    : "";
}

/**
 * Admin Dashboard → Contacts: every complaint, open ones first. The admin
 * replies (emailed to the sender and shown on their Contact page), resolves,
 * reopens or archives each one.
 */
export default function ComplaintInbox({ onChanged }) {
  const [filter, setFilter] = useState("open");
  const [items, setItems] = useState(null);
  const [meta, setMeta] = useState({ open: 0, resolved: 0 });
  const [drafts, setDrafts] = useState({});
  const [busy, setBusy] = useState("");
  const [error, setError] = useState("");
  const { showToast } = useToast();

  const load = useCallback(() => {
    api
      .adminContacts(filter === "all" ? undefined : filter)
      .then((response) => {
        setItems(response.data);
        setMeta(response.meta);
        setError("");
      })
      .catch((err) => setError(err.message));
  }, [filter]);

  useEffect(() => {
    load();
  }, [load]);

  async function run(key, action) {
    setBusy(key);
    try {
      const response = await action();
      showToast(response?.message || "Done.");
      load();
      onChanged?.();
    } catch (err) {
      showToast(err.message, "error");
    } finally {
      setBusy("");
    }
  }

  function reply(item, status) {
    const text = (drafts[item.id] ?? "").trim();
    return run(`reply-${item.id}`, async () => {
      const response = await api.adminUpdateContact(item.id, {
        ...(text ? { admin_reply: text } : {}),
        ...(status ? { status } : {}),
      });
      setDrafts((current) => ({ ...current, [item.id]: "" }));
      return response;
    });
  }

  return (
    <section className="admin-panel">
      <div className="section-row">
        <div>
          <p className="eyebrow">Inbox</p>
          <h2>Complaints and messages</h2>
        </div>
        <div className="chip-row">
          {[
            ["open", `Open (${meta.open})`],
            ["resolved", `Resolved (${meta.resolved})`],
            ["all", "All"],
          ].map(([value, label]) => (
            <button
              className={`chip chip--button ${filter === value ? "chip--active" : ""}`}
              key={value}
              onClick={() => setFilter(value)}
              type="button"
            >
              {label}
            </button>
          ))}
        </div>
      </div>

      {error && <div className="feedback feedback--error">{error}</div>}

      <div className="admin-list">
        {items === null ? (
          <div className="feedback">Loading messages...</div>
        ) : items.length === 0 ? (
          <div className="feedback">{filter === "open" ? "No open complaints. Your inbox is clear." : "Nothing here yet."}</div>
        ) : (
          items.map((item) => (
            <article className="contact-ticket contact-ticket--admin" key={item.id}>
              <div className="contact-ticket__head">
                <strong>#{item.id}</strong>
                <span className="chip">{categoryLabel(item.category)}</span>
                <span className={`contact-status contact-status--${item.status}`}>
                  {item.status === "resolved" ? "Resolved" : "Open"}
                </span>
                <span className="muted">
                  {item.name} · <a href={`mailto:${item.email}`}>{item.email}</a> · {formatDate(item.created_at)}
                </span>
              </div>
              <p>{item.message}</p>

              {item.admin_reply && (
                <div className="contact-reply">
                  <strong>Your reply</strong>
                  <p>{item.admin_reply}</p>
                </div>
              )}

              <textarea
                className="contact-reply-box"
                placeholder={item.admin_reply ? "Send another reply (optional)" : "Write a reply — it is emailed to the sender"}
                rows="3"
                value={drafts[item.id] ?? ""}
                onChange={(event) => setDrafts((current) => ({ ...current, [item.id]: event.target.value }))}
              />
              <div className="contact-actions">
                {item.status === "open" ? (
                  <>
                    <button className="button" disabled={busy !== ""} onClick={() => reply(item, "resolved")} type="button">
                      {busy === `reply-${item.id}` ? "Saving..." : (drafts[item.id] ?? "").trim() ? "Reply & resolve" : "Mark resolved"}
                    </button>
                    {(drafts[item.id] ?? "").trim() && (
                      <button className="button button--ghost" disabled={busy !== ""} onClick={() => reply(item)} type="button">
                        Reply only
                      </button>
                    )}
                  </>
                ) : (
                  <button className="button button--ghost" disabled={busy !== ""} onClick={() => reply(item, "open")} type="button">
                    {(drafts[item.id] ?? "").trim() ? "Reply & reopen" : "Reopen"}
                  </button>
                )}
                <button
                  className="button button--ghost"
                  disabled={busy !== ""}
                  onClick={() => {
                    if (window.confirm("Archive (delete) this message?")) {
                      run(`delete-${item.id}`, () => api.adminDeleteContact(item.id));
                    }
                  }}
                  type="button"
                >
                  Archive
                </button>
              </div>
            </article>
          ))
        )}
      </div>
    </section>
  );
}
