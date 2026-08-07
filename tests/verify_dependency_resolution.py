#!/usr/bin/env python3
"""Compatibility entry point for the M0.2 dependency-resolution regression suite."""
from pathlib import Path
import runpy

runpy.run_path(str(Path(__file__).with_name("verify_m02_package_state.py")), run_name="__main__")
