import axios from "axios";

const API_BASE = import.meta.env.VITE_API_URL || "http://localhost:8000/api";

export const getRecipes = async () => {
  try {
    const res = await axios.get(`${API_BASE}/recipes`);
    return res.data;
  } catch (err) {
    console.error("Error fetching recipes:", err);
    return [];
  }
};
