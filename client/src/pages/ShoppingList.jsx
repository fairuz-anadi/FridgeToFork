import { useCallback, useEffect, useMemo, useState } from "react";
import { api } from "../api/api";
import IngredientPicker from "../components/IngredientPicker";
import { useToast } from "../components/useToast";

/**
 * Auto Shopping List — what the planned week needs, grouped by aisle and
 * shareable as plain text. Fridge items stay visible with how much is held,
 * so the cook can judge whether it is enough.
 */
export default function ShoppingList({ user }) {
  const [items, setItems] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const { showToast } = useToast();

  const apply = useCallback((response) => {
    setItems(response.data);
    setMeta(response.meta);
  }, []);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      apply(await api.shoppingList());
    } catch (error) {
      showToast(error.message, "error");
    } finally {
      setLoading(false);
    }
  }, [apply, showToast]);

  useEffect(() => {
    if (user) load();
  }, [user, load]);

  const grouped = useMemo(() => {
    const buckets = {};
    items.forEach((item) => {
      (buckets[item.aisle] ??= []).push(item);
    });
    return Object.entries(buckets).sort(([a], [b]) => a.localeCompare(b));
  }, [items]);

  async function run(action, successMessage) {
    setBusy(true);
    try {
      const response = await action();
      apply(response);
      if (successMessage || response.message) {
        showToast(response.message ?? successMessage);
      }
    } catch (error) {
      showToast(error.message, "error");
    } finally {
      setBusy(false);
    }
  }

  async function copyList() {
    if (!meta?.shareable_text) return;
    try {
      await navigator.clipboard.writeText(meta.shareable_text);
      showToast("Shopping list copied to your clipboard.");
    } catch {
      showToast("Your browser blocked the clipboard. Select the text instead.", "error");
    }
  }

  if (!user) {
    return (
      <p className="mx-auto max-w-3xl px-4 py-16 text-center text-[var(--muted)]">
        Sign in to build a shopping list.
      </p>
    );
  }

  return (
    <div className="mx-auto w-full max-w-4xl px-4 py-10">
      <header className="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
          <span className="font-[var(--font-mono)] text-xs uppercase tracking-[0.14em] text-[var(--brand)]">
            Auto Shopping List
          </span>
          <h1 className="mt-2 mb-1 font-[var(--font-display)] text-3xl font-black text-[var(--text)]">
            Shopping list
          </h1>
          <p className="m-0 text-sm text-[var(--muted)]">
            {meta ? `${meta.remaining} of ${meta.total} still to buy` : "Loading…"} — items in your
            fridge are compared with what the week needs, so you only buy what&apos;s missing.
          </p>
        </div>

        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            disabled={busy}
            onClick={() =>
              run(() => api.generateShoppingList({}), "Shopping list generated.")
            }
            className="rounded-[var(--r-pill)] bg-[var(--brand)] px-5 py-2 text-sm font-semibold text-white disabled:opacity-50"
          >
            Rebuild from meal plan
          </button>
          <button
            type="button"
            onClick={copyList}
            disabled={!items.length}
            className="rounded-[var(--r-pill)] border border-[var(--border-strong)] px-4 py-2 text-sm disabled:opacity-40"
          >
            Copy list
          </button>
          <button
            type="button"
            disabled={busy || !items.some((item) => item.is_checked)}
            onClick={() => run(() => api.clearShoppingList(true), "Ticked items cleared.")}
            className="rounded-[var(--r-pill)] border border-[var(--border-strong)] px-4 py-2 text-sm disabled:opacity-40"
          >
            Clear ticked
          </button>
        </div>
      </header>

      <div className="mb-8 rounded-[var(--r-md)] border border-[var(--border)] bg-[var(--surface-strong)] p-5">
        <p className="mt-0 mb-3 text-sm font-semibold text-[var(--text)]">Add something else</p>
        <IngredientPicker
          placeholder="Milk, kitchen roll, olives…"
          onAdd={(name) => run(() => api.addShoppingItem({ name }))}
          exclude={items.map((item) => item.name)}
        />
      </div>

      {loading ? (
        <p className="text-sm text-[var(--muted)]">Loading your list…</p>
      ) : items.length === 0 ? (
        <div className="grid place-items-center rounded-[var(--r-lg)] border border-dashed border-[var(--border-strong)] p-14 text-center">
          <div className="text-4xl">🧺</div>
          <p className="mt-3 mb-0 max-w-sm text-sm text-[var(--muted)]">
            Your list is empty. Plan a few meals, then hit <strong>Rebuild from meal plan</strong>.
          </p>
        </div>
      ) : (
        <div className="grid gap-6">
          {grouped.map(([aisle, aisleItems]) => (
            <section key={aisle}>
              <h2 className="mb-2 mt-0 text-xs font-bold uppercase tracking-[0.14em] text-[var(--muted)]">
                {aisle}
              </h2>
              <ul className="m-0 grid list-none gap-1 p-0">
                {aisleItems.map((item) => (
                  <li
                    key={item.id}
                    className="group flex items-center gap-3 rounded-[var(--r-sm)] border border-[var(--border)] bg-[var(--surface-strong)] px-4 py-3"
                  >
                    <input
                      type="checkbox"
                      checked={item.is_checked}
                      onChange={() =>
                        run(() => api.updateShoppingItem(item.id, { is_checked: !item.is_checked }))
                      }
                      className="accent-[var(--brand)]"
                    />
                    <span
                      className={`flex-1 text-sm ${
                        item.is_checked ? "text-[var(--muted-light)] line-through" : "text-[var(--text)]"
                      }`}
                    >
                      <strong className="font-semibold">{formatQuantity(item)}</strong> {item.name}
                      {item.recipe_titles?.length > 0 && (
                        <em className="ml-2 text-xs not-italic text-[var(--muted-light)]">
                          for {item.recipe_titles.join(", ")}
                        </em>
                      )}
                      <PantryNote item={item} />
                    </span>
                    <button
                      type="button"
                      onClick={() => run(() => api.removeShoppingItem(item.id))}
                      aria-label={`Remove ${item.name}`}
                      className="opacity-0 transition group-hover:opacity-100"
                    >
                      ×
                    </button>
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </div>
      )}
    </div>
  );
}

const NOTE_STYLES = {
  covered: "pantry-note pantry-note--covered",
  partial: "pantry-note pantry-note--partial",
  check: "pantry-note pantry-note--check",
};

function amount(quantity, unit) {
  if (quantity === null || quantity === undefined) return "";
  const rounded = Math.round(quantity * 100) / 100;
  return `${rounded}${unit ? ` ${unit}` : ""}`;
}

/** What the fridge already holds for this item, when anything. */
function PantryNote({ item }) {
  if (!item.pantry_status) return null;

  const have = amount(item.pantry_quantity, item.pantry_unit);
  const need = amount(item.needed_quantity, item.unit);
  const text = {
    covered: `In your fridge${have ? ` (${have})` : ""} — enough for this week`,
    partial: `You have ${have}${need ? ` of ${need} needed` : ""} — buying the rest`,
    check: have
      ? `You have ${have} in your fridge — check it covers ${need || "this"}`
      : "In your fridge — amount not recorded, check you have enough",
  }[item.pantry_status];

  return <span className={NOTE_STYLES[item.pantry_status]}>{text}</span>;
}

function formatQuantity(item) {
  if (item.quantity === null || item.quantity === undefined) {
    return "";
  }

  const rounded = Math.round(item.quantity * 100) / 100;
  return `${rounded}${item.unit ? ` ${item.unit}` : ""}`;
}
