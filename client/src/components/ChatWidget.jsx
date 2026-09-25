import { useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";
import { api } from "../api/api";

const STORAGE_KEY = "fridgetofork_chat";
const GREETING = {
  role: "assistant",
  content: "Hi! Tell me what's in your fridge, or ask for a cuisine, a diet or a quick dinner idea.",
  recipes: [],
};
const SUGGESTIONS = ["I have eggs, rice and onion", "Quick vegetarian dinner", "Something Italian"];
const HISTORY_LIMIT = 12;

function loadConversation() {
  try {
    const saved = JSON.parse(sessionStorage.getItem(STORAGE_KEY));
    return Array.isArray(saved) && saved.length ? saved : [GREETING];
  } catch {
    return [GREETING];
  }
}

function RecipeChip({ recipe, onOpen }) {
  const meta = [recipe.cuisine, recipe.total_minutes ? `${recipe.total_minutes} min` : null]
    .filter(Boolean)
    .join(" · ");

  return (
    <Link className="chat-recipe" to={`/recipes/${recipe.id}/cook`} onClick={onOpen}>
      {recipe.image_url && <img src={recipe.image_url} alt="" loading="lazy" />}
      <span className="chat-recipe__text">
        <strong>{recipe.title}</strong>
        <small>
          {meta}
          {recipe.match_percent != null && ` · ${recipe.match_percent}% match`}
        </small>
      </span>
    </Link>
  );
}

export default function ChatWidget({ user }) {
  const [open, setOpen] = useState(false);
  const [messages, setMessages] = useState(loadConversation);
  const [draft, setDraft] = useState("");
  const [sending, setSending] = useState(false);
  const listRef = useRef(null);
  const inputRef = useRef(null);

  useEffect(() => {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(messages));
    } catch {
      // Storage can be unavailable (private mode); the chat still works.
    }
  }, [messages]);

  useEffect(() => {
    if (open) {
      listRef.current?.scrollTo({ top: listRef.current.scrollHeight, behavior: "smooth" });
      inputRef.current?.focus();
    }
  }, [open, messages, sending]);

  async function send(text) {
    const content = text.trim();
    if (!content || sending) return;

    const next = [...messages, { role: "user", content }];
    setMessages(next);
    setDraft("");
    setSending(true);

    try {
      const history = next.slice(-HISTORY_LIMIT).map(({ role, content: body }) => ({ role, content: body }));
      const response = await api.chat(history);
      setMessages((current) => [
        ...current,
        { role: "assistant", content: response.reply, recipes: response.recipes || [] },
      ]);
    } catch (error) {
      setMessages((current) => [
        ...current,
        { role: "assistant", content: error.message, recipes: [], failed: true },
      ]);
    } finally {
      setSending(false);
    }
  }

  function reset() {
    setMessages([GREETING]);
  }

  return (
    <div className={`chat-widget${open ? " is-open" : ""}`}>
      {open && (
        <section className="chat-panel" aria-label="Kitchen assistant">
          <header className="chat-panel__head">
            <div>
              <strong>Kitchen assistant</strong>
              <small>{user ? `Cooking with ${user.name}` : "Ask what you can cook"}</small>
            </div>
            <div className="chat-panel__actions">
              <button type="button" onClick={reset} title="Start a new chat">
                New
              </button>
              <button type="button" onClick={() => setOpen(false)} aria-label="Close chat">
                ×
              </button>
            </div>
          </header>

          <div className="chat-panel__list" ref={listRef}>
            {messages.map((message, index) => (
              <div key={index} className={`chat-msg chat-msg--${message.role}${message.failed ? " is-error" : ""}`}>
                <p>{message.content}</p>
                {message.recipes?.length > 0 && (
                  <div className="chat-msg__recipes">
                    {message.recipes.map((recipe) => (
                      <RecipeChip key={recipe.id} recipe={recipe} onOpen={() => setOpen(false)} />
                    ))}
                  </div>
                )}
              </div>
            ))}
            {sending && (
              <div className="chat-msg chat-msg--assistant chat-msg--typing" aria-label="Assistant is typing">
                <span />
                <span />
                <span />
              </div>
            )}
          </div>

          {messages.length === 1 && (
            <div className="chat-panel__suggestions">
              {SUGGESTIONS.map((suggestion) => (
                <button key={suggestion} type="button" onClick={() => send(suggestion)}>
                  {suggestion}
                </button>
              ))}
            </div>
          )}

          <form
            className="chat-panel__form"
            onSubmit={(event) => {
              event.preventDefault();
              send(draft);
            }}
          >
            <input
              ref={inputRef}
              value={draft}
              onChange={(event) => setDraft(event.target.value)}
              placeholder="Ask about recipes…"
              maxLength={1000}
              aria-label="Message"
            />
            <button type="submit" disabled={sending || !draft.trim()}>
              Send
            </button>
          </form>
        </section>
      )}

      <button
        type="button"
        className="chat-launcher"
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        aria-label={open ? "Close kitchen assistant" : "Open kitchen assistant"}
      >
        {open ? "×" : "Ask the chef"}
      </button>
    </div>
  );
}
