import { useEffect, useMemo, useRef, useState } from "react";
import { api } from "../api/api";
import ComplaintInbox from "../components/ComplaintInbox";
import { useToast } from "../components/useToast";

function formatDate(value) {
  if (!value) return "Unknown";

  return new Intl.DateTimeFormat("en-US", {
    month: "short",
    day: "numeric",
    year: "numeric",
  }).format(new Date(value));
}

function SectionEmpty({ children }) {
  return <div className="feedback">{children}</div>;
}

function StatCard({ label, value, hint, active = false, onClick }) {
  return (
    <button
      className={`admin-stat ${active ? "admin-stat--active" : ""}`}
      disabled={!onClick}
      onClick={onClick}
      type="button"
    >
      <span className="admin-stat__label">{label}</span>
      <strong className="admin-stat__value">{value}</strong>
      <span className="admin-stat__hint">{hint}</span>
    </button>
  );
}

export default function AdminDashboard() {
  const [dashboard, setDashboard] = useState(null);
  const [error, setError] = useState("");
  const [activeTab, setActiveTab] = useState("users");
  const [userQuery, setUserQuery] = useState("");
  const [busyAction, setBusyAction] = useState("");
  const [pendingAction, setPendingAction] = useState(null);
  const actionBarRef = useRef(null);
  const { showToast } = useToast();

  function loadDashboard() {
    setError("");
    api.adminDashboard().then(setDashboard).catch((err) => setError(err.message));
  }

  useEffect(() => {
    loadDashboard();
  }, []);

  async function runAdminAction(key, action) {
    setBusyAction(key);
    try {
      const response = await action();
      showToast(response?.message || "Action completed successfully.");
      setError("");
      loadDashboard();
    } catch (actionError) {
      setError(actionError.message);
      showToast(actionError.message, "error");
    } finally {
      setBusyAction("");
      setPendingAction(null);
    }
  }

  function requestAdminAction(key, action, confirmation) {
    setPendingAction({ key, action, confirmation });
    setError("");
  }

  useEffect(() => {
    if (!pendingAction || !actionBarRef.current) return;

    actionBarRef.current.scrollIntoView({
      behavior: "smooth",
      block: "start",
    });
  }, [pendingAction]);

  const filteredUsers = useMemo(() => {
    if (!dashboard) return [];

    const users = dashboard.users || [];
    const query = userQuery.trim().toLowerCase();
    if (!query) return users;

    return users.filter((user) =>
      [user.name, user.username, user.email].some((value) =>
        String(value || "")
          .toLowerCase()
          .includes(query)
      )
    );
  }, [dashboard, userQuery]);

  if (error && !dashboard) {
    return <div className="feedback feedback--error">{error}</div>;
  }

  if (!dashboard) {
    return <div className="feedback">Loading admin dashboard...</div>;
  }

  const stats = dashboard.stats || {};
  const highlights = dashboard.highlights || {};
  const recentRecipes = dashboard.recent_recipes || [];
  const recentReviews = dashboard.recent_reviews || [];
  const hasUserDirectory = Array.isArray(dashboard.users);
  const hasReviewDirectory = Array.isArray(dashboard.recent_reviews);

  return (
    <div className="admin-console">
      <section className="admin-hero">
        <div className="admin-hero__copy">
          <p className="eyebrow">Admin Console</p>
          <h1>Run FridgeToFork with confidence.</h1>
          <p className="section-copy">
            Monitor growth, moderate content, and keep the member experience clean from one
            focused operations dashboard.
          </p>
          <div className="admin-badges">
            <span className="chip">Members: {stats.members ?? stats.users ?? 0}</span>
            <span className="chip">Recipes this week: {stats.recipes_this_week ?? 0}</span>
            <span className="chip">Open complaints: {stats.contacts ?? 0}</span>
          </div>
        </div>

        <div className="admin-hero__panel">
          <div className="admin-highlight">
            <span className="admin-highlight__label">Top Category</span>
            <strong>{highlights.top_category?.name || "No activity yet"}</strong>
            <span>
              {highlights.top_category
                ? `${highlights.top_category.recipes_count ?? 0} recipes grouped here`
                : "Once recipes are categorized, trends will show here."}
            </span>
          </div>
          <div className="admin-highlight">
            <span className="admin-highlight__label">Most Reviewed Recipe</span>
            <strong>{highlights.most_reviewed_recipe?.title || "Nothing reviewed yet"}</strong>
            <span>
              {highlights.most_reviewed_recipe
                ? `${highlights.most_reviewed_recipe.reviews_count ?? 0} reviews`
                : "Recipe discussion will appear here."}
            </span>
          </div>
          <div className="admin-highlight">
            <span className="admin-highlight__label">Newest Member</span>
            <strong>{highlights.newest_member?.name || "No members yet"}</strong>
            <span>
              {highlights.newest_member
                ? `Joined ${formatDate(highlights.newest_member.created_at)}`
                : "New signups will appear here."}
            </span>
          </div>
        </div>
      </section>

      {pendingAction && (
        <section className="admin-action-bar" ref={actionBarRef}>
          <div>
            <p className="eyebrow">Confirm Action</p>
            <strong>{pendingAction.confirmation}</strong>
          </div>
          <div className="admin-action-bar__buttons">
            <button
              className="button"
              disabled={busyAction === pendingAction.key}
              onClick={() => runAdminAction(pendingAction.key, pendingAction.action)}
              type="button"
            >
              {busyAction === pendingAction.key ? "Processing..." : "Confirm"}
            </button>
            <button
              className="button button--ghost"
              disabled={busyAction === pendingAction.key}
              onClick={() => setPendingAction(null)}
              type="button"
            >
              Cancel
            </button>
          </div>
        </section>
      )}

      {error && <div className="feedback feedback--error">{error}</div>}

      <section className="admin-stats-grid">
        <StatCard
          active={activeTab === "users"}
          hint={`${stats.admins ?? 0} admins, ${stats.members ?? stats.users ?? 0} members`}
          label="Users"
          onClick={() => setActiveTab("users")}
          value={stats.users ?? 0}
        />
        <StatCard
          active={activeTab === "recipes"}
          hint={`${stats.recipes_this_week ?? 0} created this week`}
          label="Recipes"
          onClick={() => setActiveTab("recipes")}
          value={stats.recipes ?? 0}
        />
        <StatCard
          active={activeTab === "reviews"}
          hint={`${stats.reviews_per_recipe ?? 0} per recipe`}
          label="Reviews"
          onClick={() => setActiveTab("reviews")}
          value={stats.reviews ?? 0}
        />
        <StatCard
          active={activeTab === "contacts"}
          hint="Open complaints waiting for you"
          label="Contacts"
          onClick={() => setActiveTab("contacts")}
          value={stats.contacts ?? 0}
        />
        <StatCard
          label="Avg Rating"
          value={stats.average_recipe_rating ?? 0}
          hint="Across the full recipe catalog"
          onClick={() => setActiveTab("recipes")}
        />
        <StatCard
          label="New Members"
          value={stats.users_this_week ?? 0}
          hint="Joined in the last 7 days"
          onClick={() => setActiveTab("users")}
        />
      </section>

      <section className="admin-tabs">
        {[
          ["overview", "Overview"],
          ["users", "Users"],
          ["recipes", "Recipes"],
          ["reviews", "Reviews"],
          ["contacts", "Contacts"],
        ].map(([value, label]) => (
          <button
            className={`admin-tab ${activeTab === value ? "admin-tab--active" : ""}`}
            key={value}
            onClick={() => setActiveTab(value)}
            type="button"
          >
            {label}
          </button>
        ))}
      </section>

      {activeTab === "overview" && (
        <section className="admin-panel">
          <div className="section-row">
            <div>
              <p className="eyebrow">Fresh Recipes</p>
              <h2>Latest uploads</h2>
            </div>
          </div>
          <div className="admin-feed">
            {recentRecipes.length ? (
              recentRecipes.map((recipe) => (
                <div className="admin-feed-card" key={recipe.id}>
                  <div className="admin-feed-card__head">
                    <div>
                      <h3>{recipe.title}</h3>
                      <p className="muted">By {recipe.user?.name || "Unknown"} on {formatDate(recipe.created_at)}</p>
                    </div>
                    <span className="rating-pill">{recipe.average_rating || 0}/5</span>
                  </div>
                  <p className="section-copy">{recipe.description}</p>
                  <div className="chip-row">
                    {recipe.categories.map((category) => (
                      <span className="chip" key={`${recipe.id}-${category.id}`}>
                        {category.name}
                      </span>
                    ))}
                  </div>
                  <div className="admin-feed-card__foot">
                    <span>{recipe.reviews_count} reviews</span>
                    <button
                      className="button button--ghost"
                      disabled={busyAction === `recipe-${recipe.id}`}
                      onClick={() =>
                        requestAdminAction(
                          `recipe-${recipe.id}`,
                          () => api.adminDeleteRecipe(recipe.id),
                          `Delete recipe "${recipe.title}"?`
                        )
                      }
                      type="button"
                    >
                      {busyAction === `recipe-${recipe.id}` ? "Removing..." : "Remove Recipe"}
                    </button>
                  </div>
                </div>
              ))
            ) : (
              <SectionEmpty>No recipes have been uploaded yet.</SectionEmpty>
            )}
          </div>
        </section>
      )}

      {activeTab === "recipes" && (
        <section className="admin-panel">
          <div className="section-row">
            <div>
              <p className="eyebrow">Recipe List</p>
              <h2>All recent recipes in one moderation view</h2>
            </div>
          </div>

          <div className="admin-table">
            <div className="admin-table__header">
              <span>Recipe</span>
              <span>Author</span>
              <span>Reviews</span>
              <span>Rating</span>
              <span>Action</span>
            </div>
            {recentRecipes.length ? (
              recentRecipes.map((recipe) => (
                <div className="admin-table__row" key={`table-recipe-${recipe.id}`}>
                  <span>{recipe.title}</span>
                  <span>{recipe.user?.name || "Unknown"}</span>
                  <span>{recipe.reviews_count}</span>
                  <span>{recipe.average_rating || 0}/5</span>
                  <button
                    className="button button--ghost"
                    disabled={busyAction === `recipe-${recipe.id}`}
                    onClick={() =>
                      requestAdminAction(
                        `recipe-${recipe.id}`,
                        () => api.adminDeleteRecipe(recipe.id),
                        `Delete recipe "${recipe.title}"?`
                      )
                    }
                    type="button"
                  >
                    {busyAction === `recipe-${recipe.id}` ? "Removing..." : "Delete"}
                  </button>
                </div>
              ))
            ) : (
              <SectionEmpty>No recipe activity to moderate.</SectionEmpty>
            )}
          </div>
        </section>
      )}

      {activeTab === "reviews" && (
        <section className="admin-panel">
          <div className="section-row">
            <div>
              <p className="eyebrow">Review List</p>
              <h2>Direct access to recent reviews</h2>
            </div>
          </div>

          <div className="admin-list">
            {recentReviews.length ? (
              recentReviews.map((review) => (
                <div className="admin-list-row" key={`review-list-${review.id}`}>
                  <div>
                    <strong>{review.recipe?.title || "Recipe removed"}</strong>
                    <p className="muted">
                      {review.user?.name || "Unknown user"} rated it {review.rating}/5 on{" "}
                      {formatDate(review.created_at)}
                    </p>
                    <p>{review.comment || "No written review."}</p>
                  </div>
                  <button
                    className="button button--ghost"
                    disabled={busyAction === `review-${review.id}`}
                    onClick={() =>
                      requestAdminAction(
                        `review-${review.id}`,
                        () => api.adminDeleteReview(review.id),
                        "Delete this review?"
                      )
                    }
                    type="button"
                  >
                    {busyAction === `review-${review.id}` ? "Removing..." : "Delete Review"}
                  </button>
                </div>
              ))
            ) : hasReviewDirectory ? (
              <SectionEmpty>No reviews available.</SectionEmpty>
            ) : (
              <SectionEmpty>Review list is not available from the current backend response yet.</SectionEmpty>
            )}
          </div>
        </section>
      )}

      {activeTab === "users" && (
        <section className="admin-panel">
          <div className="section-row">
            <div>
              <p className="eyebrow">User Directory</p>
              <h2>Search and manage platform accounts</h2>
            </div>
            <input
              className="admin-search"
              onChange={(event) => setUserQuery(event.target.value)}
              placeholder="Search by username"
              value={userQuery}
            />
          </div>

          <div className="admin-table">
            <div className="admin-table__header admin-table__header--users">
              <span>Member</span>
              <span>Joined</span>
              <span>Recipes</span>
              <span>Reviews</span>
              <span>Points</span>
              <span>Action</span>
            </div>
            {filteredUsers.length ? (
              filteredUsers.map((user) => (
                <div className="admin-table__row admin-table__row--users" key={`user-${user.id}`}>
                  <span>
                    <strong>{user.name}</strong>
                    <small>{user.email}</small>
                  </span>
                  <span>{formatDate(user.created_at)}</span>
                  <span>{user.recipes_count}</span>
                  <span>{user.reviews_count}</span>
                  <span>{user.points}</span>
                  <button
                    className="button button--ghost"
                    disabled={busyAction === `user-${user.id}` || user.is_admin}
                    onClick={() =>
                      requestAdminAction(
                        `user-${user.id}`,
                        () => api.adminDeleteUser(user.id),
                        `Delete user "${user.name}" and all of their content?`
                      )
                    }
                    type="button"
                  >
                    {user.is_admin
                      ? "Protected"
                      : busyAction === `user-${user.id}`
                        ? "Removing..."
                        : "Delete User"}
                  </button>
                </div>
              ))
            ) : hasUserDirectory ? (
              <SectionEmpty>No users matched your search.</SectionEmpty>
            ) : (
              <SectionEmpty>User list is not available from the current backend response yet.</SectionEmpty>
            )}
          </div>
        </section>
      )}

      {activeTab === "contacts" && <ComplaintInbox onChanged={loadDashboard} />}
    </div>
  );
}
