using UnityEngine;
using UnityEngine.SceneManagement;

public class SceneChanger : MonoBehaviour
{
    // Open the Main Menu
    public void OpenMainMenu()
    {
        SceneManager.LoadScene("Main Menu");
    }

    // Open Settings
    public void OpenSettings()
    {
        SceneManager.LoadScene("Settings");
    }

    // Open Level Selection
    public void OpenLevelSelection()
    {
        SceneManager.LoadScene("LevelSelection");
    }

    // Open Character Selection
    public void OpenCharacterSelection()
    {
        SceneManager.LoadScene("CharacterSelection");
    }

    // Back to Main Menu
    public void BackToMainMenu()
    {
        SceneManager.LoadScene("Main Menu");
    }

    // Quit the game
    public void QuitGame()
    {
        Application.Quit();
    }
}