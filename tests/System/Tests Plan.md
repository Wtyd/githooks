

## Fast Strategy
The system tests for the Fast Strategy are in the `ExecuteFastStrategySystemTest` file. In all cases we consider that ALL the tools are configured.
* For the **Non-accelerable tools** (Check Security and Php Copy Paste Detector) there are two possible results, OK and KO.
* For the **accelerable tools** (Code Sniffer, Parallel-Lint and Php Stan) there are 4 possible results:
    1. OK: the tool runs and ends successfully.
    2. KO: the tool runs and ends with some error.
    3. Skip: the tool does not runs. For this, all the modified files are not in the tool's paths.
    4. OK (exclude). This means that the tool runs but the modified files are in the tool's exclude.

    The table with the tools and their possible outputs is:
    | Check Security | CPDetector | Code Sniffer | Mess Detector | Parallel-Lint |   PhpStan    |
    | :------------: | :--------: | :----------: | ------------- | :-----------: | :----------: |
    |       OK       |     OK     |      OK      | OK            |      OK       |      OK      |
    |       KO       |     KO     |      KO      | KO            |      KO       |      KO      |
    |                |            |     Skip     | Skip          |     Skip      |     Skip     |
    |                |            | OK (exclude) | OK (exclude)  | OK (exclude)  | OK (exclude) |
The result for this combination are 768 test cases. Applying the [pairwise algorithm](https://pairwise.teremokgames.com/sc50/) reduces to 22:

| Tests  | Check Security | CPDetector | Code Sniffer | Mess Detector | Parallel-Lint | PhpStan |
| :----: | :------------: | :--------: | :----------: | ------------- | :-----------: | :-----: |
| **1**  |       OK       |     KO     |      KO      | KO            |      KO       |   KO    |
| **2**  |       OK       |     OK     |     skip     | skip          |     skip      |  skip   |
| **3**  |       OK       |     KO     | OK (exclude) | OK (exclude)  | OK (exclude)  |   OK    |
| **4**  |       KO       |     KO     |     skip     | OK (exclude)  |      OK       |   OK    |
| **5**  |       KO       |     OK     | OK (exclude) | OK            |      OK       |   KO    |
| **6**  |       KO       |     KO     |      OK      | OK            |      KO       |  skip   |
| **7**  |       KO       |     OK     |      OK      | KO            |     skip      |   OK    |
| **8**  |       KO       |     OK     |      KO      | skip          | OK (exclude)  |   KO    |
| **9**  |       OK       |     OK     |      OK      | KO            | OK (exclude)  |   OK    |
| **10** |       OK       |     OK     |      KO      | OK (exclude)  |      OK       |  skip   |
| **11** |       OK       |     OK     |     skip     | OK            |      KO       |   OK    |
| **12** |       OK       |     KO     | OK (exclude) | OK            |     skip      |   KO    |
| **13** |       KO       |     KO     |      KO      | OK            |     skip      |   OK    |
| **14** |       KO       |     OK     |     skip     | OK            | OK (exclude)  |   KO    |
| **15** |       KO       |     OK     | OK (exclude) | KO            |      OK       |  skip   |
| **16** |       KO       |     KO     |      OK      | skip          |      OK       |   OK    |
| **17** |       KO       |     OK     |      OK      | OK (exclude)  |      KO       |   KO    |
| **18** |       OK       |     OK     | OK (exclude) | skip          |      KO       |   OK    |
| **19** |       OK       |     OK     |      OK      | OK (exclude)  |     skip      |   KO    |
| **20** |       OK       |     KO     |      OK      | OK            | OK (exclude)  |  skip   |
| **21** |       OK       |     OK     |      KO      | OK            |      OK       |   OK    |
| **22** |       OK       |     KO     |     skip     | KO            |      OK       |   KO    |

## Annexed
Actually GitHooks uses th Laravel's Dependency Injection Container. For a deep understanding, I recommend to read this [article](https://gist.github.com/davejamesmiller/bd857d9b0ac895df7604dd2e63b23afe). As a summary, I will explain how a few methods work:

* **make**: instead of usen **new MyClass()**. By *reflection* and *autowiring* it creates automatically all dependencies.
* **bind**: bind interfaces to implementations.
* **resolving**: instead of overriding the binding completely, you can use *resolving()* to register a callback that's called after the binding is revolved.
* **singleton**: return a new instance the first time. After, return always the same instance.
* **instance**:  if you already have an instance that you want to reuse, use the *instance()* method instead of *singleton()*.
* **extend**: alternatively you can also use `extend()` to wrap a class and return a different object. Very useful for *faker* classes.
