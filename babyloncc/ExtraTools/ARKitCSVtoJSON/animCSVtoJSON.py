import json
import re
from pathlib import Path

import pandas as pd

def parse_timecode(tc: str, fps_tc: float) -> float:
    # "HH:MM:SS:FF.mmm" where FF is frame number
    m = re.match(r"(\d+):(\d+):(\d+):(\d+)\.(\d+)", str(tc))
    if not m:
        raise ValueError(f"Bad timecode: {tc}")
    hh, mm, ss, ff, ms = map(int, m.groups())
    return hh * 3600 + mm * 60 + ss + (ff / fps_tc) + (ms / 1000.0)

def csv_to_morph_json(csv_path: str, out_path: str, fps_tc: float = 60.0):
    df = pd.read_csv(csv_path)

    t0 = parse_timecode(df.loc[0, "Timecode"], fps_tc)
    times = [parse_timecode(tc, fps_tc) - t0 for tc in df["Timecode"].tolist()]

    # Non-morph columns you likely don't want as blendshapes:
    skip = {"Timecode", "BlendshapeCount", "HeadYaw", "HeadPitch", "HeadRoll",
            "LeftEyeYaw", "LeftEyePitch", "LeftEyeRoll",
            "RightEyeYaw", "RightEyePitch", "RightEyeRoll"}

    curves = {}
    for col in df.columns:
        if col in skip:
            continue
        if df[col].dtype.kind not in "fi":
            continue

        # Store as [[timeSeconds, value], ...]
        vals = df[col].astype(float).tolist()
        curves[col] = [[float(times[i]), float(vals[i])] for i in range(len(times))]

    payload = {
        "fps": 60,  # playback fps for Babylon keyframes (can be different from fps_tc if you want)
        "duration": float(max(times)) if times else 0.0,
        "curves": curves
    }

    Path(out_path).write_text(json.dumps(payload, indent=2), encoding="utf-8")

if __name__ == "__main__":
    csv_to_morph_json("tools/M20250428_7897_260129_0_Device_f044REghAqIGqCGD_Zp16g_iPhone.csv",
                      "morph_curves.json",
                      fps_tc=60.0)
