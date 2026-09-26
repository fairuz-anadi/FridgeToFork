/** Complaint types shared by the Contact page and the admin inbox. */
export const CONTACT_CATEGORIES = [
  { value: "bug", label: "Something is broken" },
  { value: "recipe", label: "A recipe is wrong" },
  { value: "account", label: "My account" },
  { value: "suggestion", label: "Suggestion" },
  { value: "other", label: "Other" },
];

export function categoryLabel(value) {
  return CONTACT_CATEGORIES.find((item) => item.value === value)?.label ?? "Other";
}
