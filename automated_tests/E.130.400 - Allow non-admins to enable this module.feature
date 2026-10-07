Feature: E.130.400 - The system shall support the ability to allow non-admins to enable Project XML Sync external module on projects.

  As a REDCap end user
  I want to see that Project XML Sync is functioning as expected

Scenario: E.130.400 - Allow non-admins to enable this module on projects
    Given I login to REDCap with the user "Test_Admin"
    When I click on the link labeled "Control Center"
    When I click on the link labeled "Manage"
    Then I should see "External Modules - Module Manager"
    And I should NOT see "Project XML Sync - v1.0.0"
    When I click on the button labeled "Enable a module"
    And I wait for 2 seconds
    Then I should see "Available Modules"
    And I click on the button labeled "Enable" in the row labeled "Project XML Sync"
    And I wait for 1 second
    And I click on the button labeled "Enable"
    Then I should see "Project XML Sync - v1.0.0"
    
    When I click on the button labeled "Configure"
    And I check the checkbox labeled "Allow non-admins to enable this module on projects"
    And I check the checkbox labeled "Make module discoverable by users"
    And I click on the button labeled "Save"
    Then I should see "Project XML Sync - v1.0.0"
    And I logout  

    Given I login to REDCap with the user "Test_User1"
    When I create a new project named "E.130.400" by clicking on "New Project" in the menu bar, selecting "Practice / Just for fun" from the dropdown, choosing file "fixtures/cdisc_files/Project_redcap_val_nodata.xml", and clicking the "Create Project" button
    And I click on the link labeled "Manage"
    Then I should see "External Modules - Project Module Manager"
    And I should NOT see "Project XML Sync - v1.0.0"

    When I click on the button labeled "View available modules"
    Then I should see "Project XML Sync - v1.0.0"
    And I click on the button labeled "Enable" in the row labeled "Project XML Sync - v1.0.0"
    Then I should see "Project XML Sync - v1.0.0"
    And I logout

    # Disable external module in Control Center
    Given I login to REDCap with the user "Test_Admin"
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