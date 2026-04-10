import { useRef, useState } from "react";
import "./AnimationLoader.css";

interface AnimationLoaderProps {
  onSkeletalLoad?: (file: File) => void;
  onBlendshapeLoad?: (file: File) => void;
  onPlayAll?: () => void;
}

export function AnimationLoader({ onSkeletalLoad, onBlendshapeLoad, onPlayAll }: AnimationLoaderProps) {
  const [isOpen, setIsOpen] = useState(false);
  const [skeletalFile, setSkeletalFile] = useState<File | null>(null);
  const [blendshapeFile, setBlendshapeFile] = useState<File | null>(null);
  const [skeletalLoaded, setSkeletalLoaded] = useState(false);
  const [blendshapeLoaded, setBlendshapeLoaded] = useState(false);
  const [status, setStatus] = useState("");
  const [statusType, setStatusType] = useState<"" | "success" | "error">("");
  
  const skeletalInputRef = useRef<HTMLInputElement>(null);
  const blendshapeInputRef = useRef<HTMLInputElement>(null);
  
  const [skeletalDragOver, setSkeletalDragOver] = useState(false);
  const [blendshapeDragOver, setBlendshapeDragOver] = useState(false);

  const handleSkeletalFile = (file: File | null) => {
    if (!file) return;
    setSkeletalFile(file);
    setSkeletalLoaded(false);
    setStatus("");
    
    // Load immediately but don't play
    if (onSkeletalLoad) {
      onSkeletalLoad(file);
      setSkeletalLoaded(true);
    }
  };

  const handleBlendshapeFile = (file: File | null) => {
    if (!file) return;
    setBlendshapeFile(file);
    setBlendshapeLoaded(false);
    setStatus("");
    
    // Load immediately but don't play
    if (onBlendshapeLoad) {
      onBlendshapeLoad(file);
      setBlendshapeLoaded(true);
    }
  };

  const handleLoadAnimations = () => {
    if (!skeletalLoaded && !blendshapeLoaded) {
      setStatus("Please select at least one file");
      setStatusType("error");
      return;
    }

    // Play all loaded animations
    if (onPlayAll) {
      onPlayAll();
    }

    setStatus("Animations playing");
    setStatusType("success");
  };

  const handleClear = () => {
    setSkeletalFile(null);
    setBlendshapeFile(null);
    setSkeletalLoaded(false);
    setBlendshapeLoaded(false);
    setStatus("");
    setStatusType("");
  };

  const preventDefaults = (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
  };

  return (
    <>
      <button 
        className="anim-loader-toggle"
        onClick={() => setIsOpen(!isOpen)}
      >
        {isOpen ? "Close" : "Load Animations"}
      </button>

      <div className={`anim-loader-panel ${isOpen ? "" : "hidden"}`}>
        <div className="anim-loader-title">Load Animations</div>

        {/* Skeletal Animation Drop Zone */}
        <div className="anim-drop-zone">
          <label className="anim-drop-label">Skeletal Animation (.glb)</label>
          <div
            className={`anim-drop-area ${skeletalDragOver ? "drag-over" : ""} ${skeletalFile ? "loaded" : ""}`}
            onClick={() => skeletalInputRef.current?.click()}
            onDragEnter={(e) => {
              preventDefaults(e);
              setSkeletalDragOver(true);
            }}
            onDragLeave={(e) => {
              preventDefaults(e);
              setSkeletalDragOver(false);
            }}
            onDragOver={preventDefaults}
            onDrop={(e) => {
              preventDefaults(e);
              setSkeletalDragOver(false);
              const file = e.dataTransfer.files[0];
              if (file && file.name.toLowerCase().endsWith(".glb")) {
                handleSkeletalFile(file);
              }
            }}
          >
            <div className="anim-drop-text">
              {skeletalFile ? "✓ File loaded" : "Click or drop .glb file"}
            </div>
            {skeletalFile && (
              <div className="anim-drop-filename">{skeletalFile.name}</div>
            )}
          </div>
          <input
            ref={skeletalInputRef}
            type="file"
            accept=".glb"
            className="anim-drop-input"
            onChange={(e) => handleSkeletalFile(e.target.files?.[0] || null)}
          />
        </div>

        {/* Blendshape Animation Drop Zone */}
        <div className="anim-drop-zone">
          <label className="anim-drop-label">Blendshape Animation (.json)</label>
          <div
            className={`anim-drop-area ${blendshapeDragOver ? "drag-over" : ""} ${blendshapeFile ? "loaded" : ""}`}
            onClick={() => blendshapeInputRef.current?.click()}
            onDragEnter={(e) => {
              preventDefaults(e);
              setBlendshapeDragOver(true);
            }}
            onDragLeave={(e) => {
              preventDefaults(e);
              setBlendshapeDragOver(false);
            }}
            onDragOver={preventDefaults}
            onDrop={(e) => {
              preventDefaults(e);
              setBlendshapeDragOver(false);
              const file = e.dataTransfer.files[0];
              if (file && file.name.toLowerCase().endsWith(".json")) {
                handleBlendshapeFile(file);
              }
            }}
          >
            <div className="anim-drop-text">
              {blendshapeFile ? "✓ File loaded" : "Click or drop .json file"}
            </div>
            {blendshapeFile && (
              <div className="anim-drop-filename">{blendshapeFile.name}</div>
            )}
          </div>
          <input
            ref={blendshapeInputRef}
            type="file"
            accept=".json"
            className="anim-drop-input"
            onChange={(e) => handleBlendshapeFile(e.target.files?.[0] || null)}
          />
        </div>

        {/* Controls */}
        <div className="anim-controls">
          <button
            className="anim-btn anim-btn-primary"
            onClick={handleLoadAnimations}
            disabled={!skeletalLoaded && !blendshapeLoaded}
          >
            Load
          </button>
          <button
            className="anim-btn anim-btn-secondary"
            onClick={handleClear}
          >
            Clear
          </button>
        </div>

        {status && (
          <div className={`anim-status ${statusType}`}>
            {status}
          </div>
        )}
      </div>
    </>
  );
}
