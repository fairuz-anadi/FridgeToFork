const API_BASE = import.meta.env.VITE_API_URL || "http://localhost:8000/api";

function isFormData(value) {
  return typeof FormData !== "undefined" && value instanceof FormData;
}

export function getToken() {
  return localStorage.getItem("fridgetofork_token");
}

export function setToken(token) {
  if (token) {
    localStorage.setItem("fridgetofork_token", token);
  } else {
    localStorage.removeItem("fridgetofork_token");
  }
}

async function request(path, options = {}, config = {}) {
  const body = options.body;
  const headers = {
    ...(isFormData(body) ? {} : { "Content-Type": "application/json" }),
    ...(options.headers || {}),
  };

  const token = getToken();
  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  const response = await fetch(`${API_BASE}${path}`, {
    ...options,
    headers,
  });

  const contentType = response.headers.get("content-type") || "";
  const payload = contentType.includes("application/json")
    ? await response.json()
    : null;

  if (!response.ok) {
    const firstFieldError = payload?.errors
      ? Object.values(payload.errors).flat()[0]
      : null;
    const serverMessage =
      firstFieldError ||
      (payload?.message && payload.message !== "The submitted data is invalid." ? payload.message : null) ||
      payload?.message ||
      config.errorMessage ||
      "Something went wrong. Please try again.";

    const error = new Error(serverMessage);
    error.status = response.status;
    error.payload = payload;
    error.rawMessage = serverMessage;
    throw error;
  }

  return payload;
}

function appendRecipeFormData(body) {
  const formData = new FormData();
  formData.append("title", body.title);
  formData.append("description", body.description);

  body.ingredients.forEach((item, index) => {
    formData.append(`ingredients[${index}]`, item);
  });

  body.instructions.forEach((item, index) => {
    formData.append(`instructions[${index}]`, item);
  });

  body.categories.forEach((item, index) => {
    formData.append(`categories[${index}]`, item);
  });

  (body.diet_tags ?? []).forEach((item, index) => {
    formData.append(`diet_tags[${index}]`, item);
  });

  // Cuisine, timing and difficulty travel alongside the image upload.
  ["cuisine_code", "difficulty", "prep_minutes", "cook_minutes", "servings"].forEach((key) => {
    const value = body[key];
    if (value !== undefined && value !== null && value !== "") {
      formData.append(key, value);
    }
  });

  if (body.image) {
    formData.append("image", body.image);
  }

  if (body.remove_image) {
    formData.append("remove_image", "1");
  }

  return formData;
}

function hasRecipeBinaryPayload(body) {
  return Boolean(body.image) || Boolean(body.remove_image);
}

export const api = {
  login: (body) =>
    request(
      "/login",
      { method: "POST", body: JSON.stringify(body) },
      { errorMessage: "Unable to log in. Please check your credentials and try again." }
    ),
  register: (body) =>
    request(
      "/register",
      { method: "POST", body: JSON.stringify(body) },
      { errorMessage: "Unable to create your account right now. Please try again." }
    ),
  googleLogin: (idToken) =>
    request(
      "/auth/google",
      {
        method: "POST",
        body: JSON.stringify({ id_token: idToken }),
      },
      { errorMessage: "Google sign-in could not be completed. Please try again." }
    ),
  me: () => request("/me", {}, { errorMessage: "We couldn't load your profile right now." }),
  logout: () =>
    request("/logout", { method: "POST" }, { errorMessage: "We couldn't log you out right now." }),
  recipes: (params = {}) => {
    const search = new URLSearchParams();
    if (params.search) search.set("search", params.search);
    if (params.categories?.length) search.set("categories", params.categories.join(","));
    if (params.diets?.length) search.set("diets", params.diets.join(","));
    if (params.ingredients?.length) search.set("ingredients", params.ingredients.join(","));
    if (params.cuisine) search.set("cuisine", params.cuisine);
    if (params.region) search.set("region", params.region);
    if (params.difficulty) search.set("difficulty", params.difficulty);
    if (params.max_minutes) search.set("max_minutes", params.max_minutes);
    if (params.page) search.set("page", params.page);
    const suffix = search.toString() ? `?${search.toString()}` : "";
    return request(`/recipes${suffix}`, {}, { errorMessage: "We couldn't load recipes right now." });
  },
  recipe: (id) =>
    request(`/recipes/${id}`, {}, { errorMessage: "We couldn't load that recipe right now." }),
  saveRecipe: (body, recipeId = null) =>
    recipeId
      ? request(
          `/recipes/${recipeId}`,
          hasRecipeBinaryPayload(body)
            ? {
                method: "PUT",
                body: appendRecipeFormData(body),
              }
            : {
                method: "PUT",
                body: JSON.stringify(body),
              },
          {
            errorMessage: "We couldn't update this recipe right now.",
          }
        )
      : request(
          "/recipes",
          {
            method: hasRecipeBinaryPayload(body) ? "POST" : "POST",
            body: hasRecipeBinaryPayload(body)
              ? appendRecipeFormData(body)
              : JSON.stringify(body),
          },
          {
            errorMessage: "We couldn't upload your recipe right now.",
          }
        ),
  deleteRecipe: (id) =>
    request(`/recipes/${id}`, { method: "DELETE" }, { errorMessage: "We couldn't delete this recipe right now." }),
  favoriteRecipe: (id) =>
    request(
      `/recipes/${id}/favorite`,
      { method: "POST" },
      { errorMessage: "We couldn't add this recipe to favorites." }
    ),
  unfavoriteRecipe: (id) =>
    request(
      `/recipes/${id}/favorite`,
      { method: "DELETE" },
      { errorMessage: "We couldn't remove this recipe from favorites." }
    ),
  categories: () =>
    request("/categories", {}, { errorMessage: "We couldn't load recipe categories right now." }),
  submitReview: (recipeId, body) =>
    request(
      `/recipes/${recipeId}/reviews`,
      {
        method: "POST",
        body: JSON.stringify(body),
      },
      { errorMessage: "We couldn't submit your review right now." }
    ),
  deleteReview: (recipeId, reviewId) =>
    request(
      `/recipes/${recipeId}/reviews/${reviewId}`,
      { method: "DELETE" },
      { errorMessage: "We couldn't delete this review right now." }
    ),
  dashboard: () =>
    request("/dashboard", {}, { errorMessage: "We couldn't load your dashboard right now." }),
  leaderboards: () =>
    request("/leaderboards", {}, { errorMessage: "We couldn't load leaderboards right now." }),
  contact: (body) =>
    request(
      "/contact",
      { method: "POST", body: JSON.stringify(body) },
      { errorMessage: "We couldn't send your message right now." }
    ),
  adminDashboard: () =>
    request("/admin/dashboard", {}, { errorMessage: "We couldn't load the admin dashboard right now." }),
  adminDeleteRecipe: (id) =>
    request(`/admin/recipes/${id}`, { method: "DELETE" }, { errorMessage: "We couldn't delete this recipe right now." }),
  adminDeleteUser: (id) =>
    request(`/admin/users/${id}`, { method: "DELETE" }, { errorMessage: "We couldn't delete this user right now." }),
  adminDeleteReview: (id) =>
    request(`/admin/reviews/${id}`, { method: "DELETE" }, { errorMessage: "We couldn't delete this review right now." }),
  adminDeleteContact: (id) =>
    request(`/admin/contacts/${id}`, { method: "DELETE" }, { errorMessage: "We couldn't archive this message right now." }),
  sendTip: (body) =>
    request(
      "/tips",
      { method: "POST", body: JSON.stringify(body) },
      { errorMessage: "We couldn't send your tip right now." }
    ),
  getUserTips: (userId) =>
    request(`/users/${userId}/tips`, {}, { errorMessage: "We couldn't load tips right now." }),

  // ── Account & Profiles ────────────────────────────────────────────────
  profile: () => request("/profile", {}, { errorMessage: "We couldn't load your profile right now." }),
  updateProfile: (body) =>
    request(
      "/profile",
      { method: "PUT", body: JSON.stringify(body) },
      { errorMessage: "We couldn't save your preferences right now." }
    ),

  // ── Ingredient-Based Search ───────────────────────────────────────────
  ingredients: (params = {}) => {
    const search = new URLSearchParams();
    if (params.search) search.set("search", params.search);
    if (params.staples) search.set("staples", "1");
    if (params.limit) search.set("limit", params.limit);
    const suffix = search.toString() ? `?${search.toString()}` : "";
    return request(`/ingredients${suffix}`, {}, { errorMessage: "We couldn't load ingredients right now." });
  },
  pantry: () => request("/pantry", {}, { errorMessage: "We couldn't load your fridge right now." }),
  addPantryItem: (body) =>
    request(
      "/pantry",
      { method: "POST", body: JSON.stringify(body) },
      { errorMessage: "We couldn't add that to your fridge." }
    ),
  syncPantry: (names) =>
    request(
      "/pantry",
      { method: "PUT", body: JSON.stringify({ names }) },
      { errorMessage: "We couldn't update your fridge." }
    ),
  removePantryItem: (id) =>
    request(
      `/pantry/${id}`,
      { method: "DELETE" },
      { errorMessage: "We couldn't remove that from your fridge." }
    ),
  searchByIngredients: (body) =>
    request(
      "/pantry/search",
      { method: "POST", body: JSON.stringify(body) },
      { errorMessage: "We couldn't run that search right now." }
    ),

  // ── Recipe chatbot ────────────────────────────────────────────────────
  chat: (messages) =>
    request(
      "/chat",
      { method: "POST", body: JSON.stringify({ messages }) },
      { errorMessage: "The kitchen assistant couldn't answer right now." }
    ),

  // ── Cuisine Map Explorer ──────────────────────────────────────────────
  cuisines: () => request("/cuisines", {}, { errorMessage: "We couldn't load the cuisine map right now." }),
  cuisine: (code) =>
    request(`/cuisines/${code}`, {}, { errorMessage: "We couldn't load recipes for that country." }),

  // ── Guided Cooking Mode ───────────────────────────────────────────────
  cookMode: (recipeId, servings) =>
    request(
      `/recipes/${recipeId}/cook${servings ? `?servings=${servings}` : ""}`,
      {},
      { errorMessage: "We couldn't start cook mode for this recipe." }
    ),

  // ── Meal Planner ──────────────────────────────────────────────────────
  mealPlan: (weekStart) =>
    request(
      `/meal-plan${weekStart ? `?week_start=${weekStart}` : ""}`,
      {},
      { errorMessage: "We couldn't load your meal plan right now." }
    ),
  addMealPlanEntry: (body) =>
    request(
      "/meal-plan",
      { method: "POST", body: JSON.stringify(body) },
      { errorMessage: "We couldn't add that to your meal plan." }
    ),
  updateMealPlanEntry: (id, body) =>
    request(
      `/meal-plan/${id}`,
      { method: "PUT", body: JSON.stringify(body) },
      { errorMessage: "We couldn't update that meal." }
    ),
  removeMealPlanEntry: (id) =>
    request(
      `/meal-plan/${id}`,
      { method: "DELETE" },
      { errorMessage: "We couldn't remove that meal." }
    ),

  // ── Auto Shopping List ────────────────────────────────────────────────
  shoppingList: () =>
    request("/shopping-list", {}, { errorMessage: "We couldn't load your shopping list right now." }),
  addShoppingItem: (body) =>
    request(
      "/shopping-list",
      { method: "POST", body: JSON.stringify(body) },
      { errorMessage: "We couldn't add that to your list." }
    ),
  generateShoppingList: (body = {}) =>
    request(
      "/shopping-list/generate",
      { method: "POST", body: JSON.stringify(body) },
      { errorMessage: "We couldn't build your shopping list right now." }
    ),
  updateShoppingItem: (id, body) =>
    request(
      `/shopping-list/${id}`,
      { method: "PUT", body: JSON.stringify(body) },
      { errorMessage: "We couldn't update that item." }
    ),
  removeShoppingItem: (id) =>
    request(
      `/shopping-list/${id}`,
      { method: "DELETE" },
      { errorMessage: "We couldn't remove that item." }
    ),
  clearShoppingList: (checkedOnly = false) =>
    request(
      "/shopping-list/clear",
      { method: "POST", body: JSON.stringify({ checked_only: checkedOnly }) },
      { errorMessage: "We couldn't clear your list right now." }
    ),
};
