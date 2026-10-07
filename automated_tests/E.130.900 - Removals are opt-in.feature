Feature: E.130.900 - The system shall list items that exist in the project but not in the uploaded XML as removals that are unselected by default, and shall protect items that hold data.

  As a REDCap end user
  I want removals to need an explicit decision
  So that nothing is deleted from a live trial by accident

  Scenario: Enable external module from Control Center
    Given I login to REDCap with the user "Test_Admin"
    When I click on the link labeled "Control Center"
    And I click on the link labeled "Manage"
    When I click on the button labeled "Enable a module"
    And I wait for 2 seconds
    And I click on the button labeled "Enable" in the row labeled "Project XML Sync"
    And I wait for 1 second
    And I click on the button labeled "Enable"
    Then I should see "Project XML Sync - v1.0.0"

  Scenario: E.130.900 - Removals are listed, unselected and guarded
    # The production project is created from the AMENDED design and compared with the BASE
    # design, so the amendments show up as removals.
    Given I create a new project named "E.130.900" by clicking on "New Project" in the menu bar, selecting "Practice / Just for fun" from the dropdown, choosing file "fixtures/cdisc_files/E130_amended.xml", and clicking the "Create Project" button
    And I click on the link labeled "Manage"
    When I click on the button labeled "Enable a module"
    And I click on the button labeled "Enable" in the row labeled "Project XML Sync - v1.0.0"
    Then I should see "Project XML Sync - v1.0.0"

    When I click on the link labeled "Project XML Sync"
    And I upload a "xml" format file located at "fixtures/cdisc_files/E130_base.xml", by clicking the button near "Select the Project XML file" to browse for the file, and clicking the button labeled "Upload and compare" to upload the file
    Then I should see "Differences"
    And I should see "Removed"
    And I should see "ae_term"
    And I should see "AE grade missing"
    # The base XML has no alerts/surveys sections: the user is warned
    And I should see "The uploaded XML has no \"Alerts & notifications\" section"
    # Surveys are never deleted by the module
    And I should see "Surveys are never deleted here"
    # Alerts are deactivated, not deleted
    And I should see "The alert is deactivated (not permanently deleted)"

    # Applying the default selection removes nothing
    When I click on the button labeled "Apply selected changes"
    Then I should see "change(s) applied"
    When I click on the link labeled "Data Quality"
    Then I should see "AE grade missing"
    When I click on the link labeled "Alerts & Notifications"
    Then I should see "New AE alert"

    # Disable external module in Control Center
    When I click on the link labeled "Control Center"
    And I click on the link labeled "Manage"
    And I click on the button labeled "Disable"
    Then I should see "Disable module?"
    When I click on the button labeled "Disable module"
    Then I should NOT see "Project XML Sync - v1.0.0"
    And I logout

    # Verify no exceptions are thrown in the system
    Given I open Email
    Then I should NOT see an email with subject "REDCap External Module Hook Exception - project_xml_sync"
