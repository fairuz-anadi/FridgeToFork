import { useCallback, useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { api } from "../api/api";
import FridgeScanner from "../components/FridgeScanner";
import IngredientPicker from "../components/IngredientPicker";
import NutritionPanel from "../components/NutritionPanel";
import { useToast } from "../components/useToast";

const UNITS = ["", "g", "kg", "ml", "l", "piece", "cup", "tbsp", "tsp", "packet", "can", "bunch"];

function expiryLabel(item) {
  const days = item.days_left;
  if (days === null || days === undefined) return null;
  if (days < 0) return "expired";
  if (days === 0) return "today";
  if (days === 1) return "1 day";
  return `${days} days`;
}

function daysPhrase(days) {
  if (days === 0) return "today";
  if (days === 1) return "tomorrow";
  return `${days} days`;
}

const QUICK_ADD = [
  "Onion", "Garlic", "Tomato", "Egg", "Rice", "Pasta", "Potato",
  "Chicken Breast", "Olive Oil", "Butter", "Milk", "Lentils", "Cheddar Cheese",
];

/**
 * "What's in my fridge" — the ingredient-based search screen.
 *
 * Signed-in cooks get a saved fridge; signed-out visitors can still type
 * ingredients in for a one-off search.
 */
export default function PantryPage({ user, onRequireAuth }) {
  const [pantry, setPantry] = useState([]);
  const [guestIngredients, setGuestIngredients] = useState([]);
  const [matches, setMatches] = useState([]);
  const [meta, setMeta] = useState(null);
  const [searching, setSearching] = useState(false);
  // 10 on the slider means "don't filter on missing count at all".
  const [maxMissing, setMaxMissing] = useState(4);
  const [maxMinutes, setMaxMinutes] = useState("");
  const [skill, setSkill] = useState("");
  const [editing, setEditing] = useState(null);
  const { showToast } = useToast();

  const ingredientNames = useMemo(
    () => (user ? pantry.map((item) => item.ingredient?.name).filter(Boolean) : guestIngredients),
    [user, pantry, guestIngredients]
  );

  const quickAdd = useMemo(() => {
    const owned = new Set(ingredientNames.map((name) => name.toLowerCase()));
    return QUICK_ADD.filter((name) => !owned.has(name.toLowerCase()));
  }, [ingredientNames]);

  const loadPantry = useCallback(async () => {
    if (!user) return;
    try {
      const response = await api.pantry();
      setPantry(response.data);
    } catch (error) {
      showToast(error.message, "error");
    }
  }, [user, showToast]);

  useEffect(() => {
    loadPantry();
  }, [loadPantry]);

  const runSearch = useCallback(async () => {
    if (ingredientNames.length === 0) {
      setMatches([]);
      setMeta(null);
      return;
    }

    setSearching(true);
    try {
      const response = await api.searchByIngredients({
        ingredients: ingredientNames,
        max_missing: maxMissing >= 10 ? 50 : maxMissing,
        ...(maxMinutes ? { max_minutes: Number(maxMinutes) } : {}),
        ...(skill ? { skill } : {}),
      });
      setMatches(response.data);
      setMeta(response.meta);
    } catch (error) {
      showToast(error.message, "error");
    } finally {
      setSearching(false);
    }
  }, [ingredientNames, maxMissing, maxMinutes, skill, showToast]);

  useEffect(() => {
    const timer = setTimeout(runSearch, 250);
    return () => clearTimeout(timer);
  }, [runSearch]);

  async function addIngredient(name) {
    if (!user) {
      setGuestIngredients((current) =>
        current.some((item) => item.toLowerCase() === name.toLowerCase()) ? current : [...current, name]
      );
      return;
    }

    try {
      const response = await api.addPantryItem({ name });
      setPantry(response.data);
    } catch (error) {
      showToast(error.message, "error");
    }
  }

  async function addMany(names) {
    if (!user) {
      setGuestIngredients((current) => {
        const owned = new Set(current.map((name) => name.toLowerCase()));
        return [...current, ...names.filter((name) => !owned.has(name.toLowerCase()))];
      });
      return;
    }

    try {
      const response = await api.syncPantry([...ingredientNames, ...names]);
      setPantry(response.data);
    } catch (error) {
      showToast(error.message, "error");
    }
  }

  async function saveItem(item, changes) {
    try {
      const response = await api.updatePantryItem(item.id, changes);
      setPantry(response.data);
      setEditing(null);
      showToast(`${item.ingredient?.name} updated.`);
    } catch (error) {
      showToast(error.message, "error");
    }
  }

  async function removeIngredient(item) {
    if (!user) {
      setGuestIngredients((current) => current.filter((name) => name !== item));
      return;
    }

    try {
      const response = await api.removePantryItem(item.id);
      setPantry(response.data);
    } catch (error) {
      showToast(error.message, "error");
    }
  }

  const cookNow = matches.filter((match) => match.match_ratio === 1);
  const almost = matches.filter((match) => match.match_ratio < 1);

  return (
    <div className="mx-auto grid w-full max-w-6xl gap-8 px-4 py-10 lg:grid-cols-[340px_1fr]">
      {/* ── Fridge ─────────────────────────────────────────────── */}
      <aside className="h-fit rounded-[var(--r-lg)] border border-[var(--border)] bg-[var(--surface-strong)] p-6 shadow-[var(--shadow-sm)]">
        <h1 className="m-0 font-[var(--font-display)] text-2xl font-black text-[var(--text)]">
          What&apos;s in your fridge?
        </h1>
        <p className="mt-2 mb-5 text-sm leading-relaxed text-[var(--muted)]">
          {user
            ? "Your fridge is saved, so this list is waiting for you next time."
            : "Add ingredients to search now — sign in to keep your fridge between visits."}
        </p>

        <FridgeScanner owned={ingredientNames} onAdd={addMany} />

        <IngredientPicker onAdd={addIngredient} exclude={ingredientNames} />

        <div className="mt-5 flex flex-wrap gap-2">
          {user
            ? pantry.map((item) => (
                <Chip
                  key={item.id}
                  label={item.ingredient?.name}
                  item={item}
                  active={editing === item.id}
                  onEdit={() => setEditing(editing === item.id ? null : item.id)}
                  onRemove={() => removeIngredient(item)}
                />
              ))
            : guestIngredients.map((name) => (
                <Chip key={name} label={name} onRemove={() => removeIngredient(name)} />
              ))}
        </div>

        {user && pantry.length > 0 && !editing && (
          <p className="mt-3 mb-0 text-xs text-[var(--muted-light)]">
            Tap an item to record how much you have and when it expires.
          </p>
        )}

        {user && editing && (
          <ItemEditor
            key={editing}
            item={pantry.find((item) => item.id === editing)}
            onSave={saveItem}
            onClose={() => setEditing(null)}
          />
        )}

        {quickAdd.length > 0 && (
          <div className="mt-5">
            <p className="mb-2 text-xs uppercase tracking-wider text-[var(--muted-light)]">Quick add</p>
            <div className="flex flex-wrap gap-2">
              {quickAdd.map((name) => (
                <button
                  key={name}
                  type="button"
                  onClick={() => addIngredient(name)}
                  className="rounded-[var(--r-pill)] border border-[var(--border-strong)] px-3 py-1.5 text-xs hover:border-[var(--brand)] hover:text-[var(--brand)]"
                >
                  + {name}
                </button>
              ))}
            </div>
          </div>
        )}

        <hr className="my-6 border-0 border-t border-[var(--border)]" />

        <div className="grid gap-4">
          <label className="grid gap-1.5 text-sm">
            <span className="font-semibold text-[var(--text)]">
              Missing ingredients allowed: {maxMissing >= 10 ? "any" : maxMissing}
            </span>
            <input
              type="range"
              min="0"
              max="10"
              value={maxMissing}
              onChange={(event) => setMaxMissing(Number(event.target.value))}
              className="accent-[var(--brand)]"
            />
          </label>

          <label className="grid gap-1.5 text-sm">
            <span className="font-semibold text-[var(--text)]">Ready within</span>
            <select
              value={maxMinutes}
              onChange={(event) => setMaxMinutes(event.target.value)}
              className="rounded-[var(--r-sm)] border border-[var(--border-strong)] bg-white px-3 py-2"
            >
              <option value="">Any time</option>
              <option value="20">20 minutes</option>
              <option value="30">30 minutes</option>
              <option value="45">45 minutes</option>
              <option value="90">90 minutes</option>
            </select>
          </label>

          <label className="grid gap-1.5 text-sm">
            <span className="font-semibold text-[var(--text)]">Skill level</span>
            <select
              value={skill}
              onChange={(event) => setSkill(event.target.value)}
              className="rounded-[var(--r-sm)] border border-[var(--border-strong)] bg-white px-3 py-2"
            >
              <option value="">
                {user ? `My profile (${user.skill_level ?? "beginner"})` : "Any level"}
              </option>
              <option value="beginner">Beginner only</option>
              <option value="intermediate">Up to intermediate</option>
              <option value="advanced">Anything</option>
            </select>
          </label>
        </div>

        {!user && (
          <button
            type="button"
            onClick={onRequireAuth}
            className="mt-6 w-full rounded-[var(--r-pill)] bg-[var(--accent)] px-4 py-2.5 text-sm font-semibold text-white"
          >
            Sign in to save your fridge
          </button>
        )}
      </aside>

      {/* ── Matches ────────────────────────────────────────────── */}
      <section>
        {ingredientNames.length === 0 ? (
          <EmptyState />
        ) : (
          <>
            <header className="mb-6 flex flex-wrap items-baseline justify-between gap-3">
              <h2 className="m-0 font-[var(--font-display)] text-2xl font-black text-[var(--text)]">
                {searching ? "Searching…" : `${matches.length} recipes you can make`}
              </h2>
              {meta && (
                <p className="m-0 text-sm text-[var(--muted)]">
                  {meta.cook_now} ready right now from {meta.ingredient_count} ingredients
                </p>
              )}
            </header>

            {meta?.use_soon?.length > 0 && (
              <div className="use-soon">
                <strong>Use these soon:</strong>{" "}
                {meta.use_soon.map((item) => `${item.name} (${daysPhrase(item.days_left)})`).join(", ")}
                <span> — recipes that use them are shown first.</span>
              </div>
            )}

            {cookNow.length > 0 && (
              <MatchGroup
                title="Cook right now"
                caption="Everything on the list is already in your fridge."
                matches={cookNow}
              />
            )}

            {almost.length > 0 && (
              <MatchGroup
                title="Almost there"
                caption="A short shopping trip away."
                matches={almost}
              />
            )}

            {!searching && matches.length === 0 && (
              <p className="rounded-[var(--r-md)] border border-dashed border-[var(--border-strong)] p-8 text-center text-[var(--muted)]">
                Nothing matched. Try allowing more missing ingredients, or add a few staples to your fridge.
              </p>
            )}
          </>
        )}
      </section>
    </div>
  );
}

function Chip({ label, item, active, onEdit, onRemove }) {
  const expiry = item ? expiryLabel(item) : null;
  const amount = item?.quantity ? `${Number(item.quantity)}${item.unit ? ` ${item.unit}` : ""}` : null;
  const state = `${item?.expiry_status ? ` is-${item.expiry_status}` : ""}${active ? " is-active" : ""}`;

  return (
    <span
      className={`fridge-chip inline-flex items-center gap-2 rounded-[var(--r-pill)] bg-[var(--brand-glow)] py-1.5 pl-3 pr-2 text-sm text-[var(--brand-deep)]${state}`}
    >
      {onEdit ? (
        <button type="button" onClick={onEdit} className="fridge-chip__label" title="Edit amount and expiry">
          {label}
          {amount && <small>{amount}</small>}
          {expiry && <em>{expiry}</em>}
        </button>
      ) : (
        label
      )}
      <button
        type="button"
        onClick={onRemove}
        aria-label={`Remove ${label}`}
        className="grid h-5 w-5 place-items-center rounded-full bg-[rgba(10,58,36,0.15)] text-xs leading-none"
      >
        ×
      </button>
    </span>
  );
}

function ItemEditor({ item, onSave, onClose }) {
  const [quantity, setQuantity] = useState(item?.quantity ?? "");
  const [unit, setUnit] = useState(item?.unit ?? "");
  const [expiresOn, setExpiresOn] = useState(item?.expires_on ?? "");

  if (!item) return null;

  return (
    <form
      className="fridge-editor"
      onSubmit={(event) => {
        event.preventDefault();
        onSave(item, {
          quantity: quantity === "" ? null : Number(quantity),
          unit: unit || null,
          expires_on: expiresOn || null,
        });
      }}
    >
      <p className="fridge-editor__title">{item.ingredient?.name}</p>
      <div className="fridge-editor__row">
        <label>
          <span>Amount</span>
          <input
            type="number"
            min="0"
            step="any"
            value={quantity}
            onChange={(event) => setQuantity(event.target.value)}
            placeholder="e.g. 500"
          />
        </label>
        <label>
          <span>Unit</span>
          <select value={unit} onChange={(event) => setUnit(event.target.value)}>
            {UNITS.map((option) => (
              <option key={option} value={option}>
                {option || "count"}
              </option>
            ))}
          </select>
        </label>
      </div>
      <label>
        <span>Expires on</span>
        <input type="date" value={expiresOn} onChange={(event) => setExpiresOn(event.target.value)} />
      </label>
      <p className="fridge-editor__hint">
        Recording the amount lets the shopping list buy only what you&apos;re missing.
      </p>
      <div className="fridge-editor__actions">
        <button type="button" onClick={onClose} className="is-quiet">
          Close
        </button>
        <button type="submit">Save</button>
      </div>
    </form>
  );
}

function MatchGroup({ title, caption, matches }) {
  return (
    <div className="mb-10">
      <div className="mb-4">
        <h3 className="m-0 text-lg font-bold text-[var(--text)]">{title}</h3>
        <p className="m-0 text-sm text-[var(--muted)]">{caption}</p>
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        {matches.map((match) => (
          <MatchCard key={match.recipe.id} match={match} />
        ))}
      </div>
    </div>
  );
}

function MatchCard({ match }) {
  const { recipe } = match;

  return (
    <article className="flex flex-col gap-3 rounded-[var(--r-md)] border border-[var(--border)] bg-[var(--surface-strong)] p-5 shadow-[var(--shadow-xs)]">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h4 className="m-0 text-base font-bold text-[var(--text)]">{recipe.title}</h4>
          <p className="m-0 mt-1 text-xs text-[var(--muted)]">
            {recipe.cuisine_country ?? "Uncategorised"} · {recipe.difficulty}
            {recipe.total_minutes ? ` · ${recipe.total_minutes} min` : ""}
          </p>
        </div>
        <span
          className={`shrink-0 rounded-[var(--r-pill)] px-2.5 py-1 text-xs font-bold ${
            match.match_percent === 100
              ? "bg-[var(--accent)] text-white"
              : "bg-[var(--gold-light)] text-[var(--brand-deep)]"
          }`}
        >
          {match.match_percent}%
        </span>
      </div>

      <NutritionPanel nutrition={recipe.nutrition} compact />

      {match.uses_expiring?.length > 0 && (
        <p className="use-soon-badge">
          Uses your {match.uses_expiring.join(", ")} before{" "}
          {match.uses_expiring.length === 1 ? "it expires" : "they expire"}
        </p>
      )}

      {match.missing.length > 0 && (
        <p className="m-0 text-xs text-[var(--muted)]">
          <strong className="text-[var(--brand-deep)]">Missing:</strong>{" "}
          {match.missing.map((item) => item.name).join(", ")}
        </p>
      )}

      <div className="mt-auto flex gap-2 pt-1">
        <Link
          to={`/recipes/${recipe.id}/cook`}
          className="rounded-[var(--r-pill)] bg-[var(--brand)] px-4 py-2 text-xs font-semibold text-white"
        >
          Start cooking
        </Link>
        <Link
          to="/recipes"
          className="rounded-[var(--r-pill)] border border-[var(--border-strong)] px-4 py-2 text-xs font-semibold"
        >
          Browse library
        </Link>
      </div>
    </article>
  );
}

function EmptyState() {
  return (
    <div className="grid place-items-center rounded-[var(--r-lg)] border border-dashed border-[var(--border-strong)] p-16 text-center">
      <div className="text-5xl">🥕</div>
      <h2 className="mt-4 mb-2 font-[var(--font-display)] text-2xl font-black text-[var(--text)]">
        Start with what you have
      </h2>
      <p className="m-0 max-w-md text-sm leading-relaxed text-[var(--muted)]">
        Add a few ingredients on the left and we&apos;ll rank every recipe by how much of it
        you can already make — no shopping trip required.
      </p>
    </div>
  );
}
