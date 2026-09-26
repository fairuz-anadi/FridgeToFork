import { useRef, useState } from "react";
import { api } from "../api/api";
import { useToast } from "./useToast";

const MAX_SIDE = 1280;

/** Shrink the photo before upload — phone pictures are often 5–10 MB. */
async function downscale(file) {
  try {
    const bitmap = await createImageBitmap(file);
    const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement("canvas");
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    canvas.getContext("2d").drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, "image/jpeg", 0.85));
    return blob ? new File([blob], "fridge.jpg", { type: "image/jpeg" }) : file;
  } catch {
    return file;
  }
}

/**
 * "Snap your fridge": take or pick a photo, let Claude list the ingredients,
 * then the cook unticks anything wrong before adding them.
 */
export default function FridgeScanner({ owned, onAdd }) {
  const inputRef = useRef(null);
  const [scanning, setScanning] = useState(false);
  const [preview, setPreview] = useState(null);
  const [found, setFound] = useState(null);
  const [selected, setSelected] = useState(new Set());
  const { showToast } = useToast();

  const ownedSet = new Set(owned.map((name) => name.toLowerCase()));

  async function handleFile(event) {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (!file) return;

    setPreview((current) => {
      if (current) URL.revokeObjectURL(current);
      return URL.createObjectURL(file);
    });
    setFound(null);
    setScanning(true);

    try {
      const response = await api.scanPantryPhoto(await downscale(file));
      const fresh = response.data.filter((item) => !ownedSet.has(item.name.toLowerCase()));
      setFound(response.data);
      setSelected(new Set(fresh.filter((item) => item.confidence !== "low").map((item) => item.name)));
      showToast(response.message);
    } catch (error) {
      showToast(error.message, "error");
      close();
    } finally {
      setScanning(false);
    }
  }

  function toggle(name) {
    setSelected((current) => {
      const next = new Set(current);
      next.has(name) ? next.delete(name) : next.add(name);
      return next;
    });
  }

  function close() {
    setFound(null);
    setPreview((current) => {
      if (current) URL.revokeObjectURL(current);
      return null;
    });
  }

  async function addSelected() {
    await onAdd([...selected]);
    showToast(`${selected.size} ingredient${selected.size === 1 ? "" : "s"} added to your fridge.`);
    close();
  }

  return (
    <div className="fridge-scan">
      <input
        ref={inputRef}
        type="file"
        accept="image/*"
        capture="environment"
        onChange={handleFile}
        hidden
      />
      <button
        type="button"
        className="fridge-scan__button"
        onClick={() => inputRef.current?.click()}
        disabled={scanning}
      >
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M4 8.5A2.5 2.5 0 0 1 6.5 6h1.7l1.2-1.8A1.5 1.5 0 0 1 10.6 3.5h2.8a1.5 1.5 0 0 1 1.2.7L15.8 6h1.7A2.5 2.5 0 0 1 20 8.5v8a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 16.5z" />
          <circle cx="12" cy="12.5" r="3.4" />
        </svg>
        {scanning ? "Scanning your photo…" : "Snap your fridge"}
      </button>
      <p className="fridge-scan__hint">Take a photo and we&apos;ll list what&apos;s inside.</p>

      {(scanning || found) && (
        <div className="fridge-scan__panel">
          {preview && <img src={preview} alt="Your fridge photo" className={scanning ? "is-scanning" : ""} />}

          {scanning && <p className="fridge-scan__status">Looking for ingredients…</p>}

          {found && (
            <>
              {found.length === 0 ? (
                <p className="fridge-scan__status">No ingredients spotted. Try a closer, brighter photo.</p>
              ) : (
                <ul>
                  {found.map((item) => {
                    const have = ownedSet.has(item.name.toLowerCase());
                    return (
                      <li key={item.name}>
                        <label className={have ? "is-owned" : ""}>
                          <input
                            type="checkbox"
                            checked={have || selected.has(item.name)}
                            disabled={have}
                            onChange={() => toggle(item.name)}
                          />
                          <span>{item.name}</span>
                          {have ? (
                            <small>already in fridge</small>
                          ) : (
                            item.confidence !== "high" && <small>{item.confidence === "low" ? "not sure" : "probably"}</small>
                          )}
                        </label>
                      </li>
                    );
                  })}
                </ul>
              )}

              <div className="fridge-scan__actions">
                <button type="button" onClick={close} className="is-quiet">
                  Cancel
                </button>
                <button type="button" onClick={addSelected} disabled={selected.size === 0}>
                  Add {selected.size || ""} to fridge
                </button>
              </div>
            </>
          )}
        </div>
      )}
    </div>
  );
}
