import { useCallback, useEffect, useState } from "react";
import { api } from "../api/api";
import { CONTACT_CATEGORIES, categoryLabel } from "../components/contactCategories";
import { useToast } from "../components/useToast";

// Archived complaints are closed from the sender's point of view.
const STATUS_LABELS = { open: "Open", resolved: "Resolved", archived: "Closed" };

function formatDate(value) {
  return value
    ? new Intl.DateTimeFormat("en-US", { month: "short", day: "numeric", year: "numeric" }).format(new Date(value))
    : "";
}

export default function ContactPage({ user, onRequireAuth }) {
  const [category, setCategory] = useState("bug");
  const [message, setMessage] = useState("");
  const [sending, setSending] = useState(false);
  const [error, setError] = useState("");
  const [history, setHistory] = useState([]);
  const { showToast } = useToast();

  const loadHistory = useCallback(() => {
    if (!user) return;
    api
      .myContacts()
      .then((response) => setHistory(response.data))
      .catch(() => {});
  }, [user]);

  useEffect(() => {
    loadHistory();
  }, [loadHistory]);

  async function handleSubmit(event) {
    event.preventDefault();
    setError("");

    if (!user) {
      onRequireAuth?.();
      return;
    }

    if (message.trim().length < 10) {
      setError("Please describe the problem in at least 10 characters.");
      return;
    }

    setSending(true);
    try {
      await api.contact({ category, message });
      showToast("Your message has been sent to the admin.");
      setMessage("");
      loadHistory();
    } catch (submitError) {
      setError(submitError.message);
      showToast(submitError.message, "error");
    } finally {
      setSending(false);
    }
  }

  return (
    <div className="simple-page">
      <p className="eyebrow">Contact Admin</p>
      <h1>Report issues or ask for help.</h1>
      <p className="muted">
        Your message goes straight to the admin&apos;s inbox and email. You&apos;ll see the reply here
        {user ? ` and at ${user.email}` : ""}.
      </p>

      <form className="stack-form recipe-form" onSubmit={handleSubmit}>
        {user && (
          <p className="muted contact-from">
            Sending as <strong>{user.name}</strong> · {user.email}
          </p>
        )}
        <label className="contact-field">
          <span>What is it about?</span>
          <select value={category} onChange={(event) => setCategory(event.target.value)} disabled={!user}>
            {CONTACT_CATEGORIES.map((item) => (
              <option key={item.value} value={item.value}>
                {item.label}
              </option>
            ))}
          </select>
        </label>
        <textarea
          placeholder="Tell the admin what went wrong"
          rows="7"
          value={message}
          onChange={(event) => setMessage(event.target.value)}
          disabled={!user}
          required
        />
        {error && <p className="form-error">{error}</p>}
        {user ? (
          <button className="button" type="submit" disabled={sending}>
            {sending ? "Sending..." : "Send Message"}
          </button>
        ) : (
          <button className="button" onClick={onRequireAuth} type="button">
            Log In to Contact Admin
          </button>
        )}
      </form>

      {user && history.length > 0 && (
        <section className="contact-history">
          <h2>Your messages</h2>
          {history.map((item) => (
            <article className="contact-ticket" key={item.id}>
              <div className="contact-ticket__head">
                <span className="chip">{categoryLabel(item.category)}</span>
                <span className={`contact-status contact-status--${item.status}`}>
                  {STATUS_LABELS[item.status] ?? "Open"}
                </span>
                <span className="muted">{formatDate(item.created_at)}</span>
              </div>
              <p>{item.message}</p>
              {item.admin_reply ? (
                <div className="contact-reply">
                  <strong>Admin reply</strong>
                  <p>{item.admin_reply}</p>
                </div>
              ) : (
                <p className="muted">Waiting for the admin to reply.</p>
              )}
            </article>
          ))}
        </section>
      )}
    </div>
  );
}
